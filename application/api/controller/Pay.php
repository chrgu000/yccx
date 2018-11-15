<?php

namespace app\api\controller;

use app\common\controller\UserBase;
use app\common\model\CustomerBalance;
use app\common\model\CustomerCash;
use definition\GoodsType;
use think\Config;
use think\Session;
use wxpay\AppApiPay;
use wxpay\database\WxPayRefund;
use wxpay\database\WxPayUnifiedOrder;
use wxpay\JsApiPay;
use wxpay\NativePay;
use wxpay\WxPayApi;
use app\common\model\Order as OrderModel;

/**
 * 样例控制器
 *
 * Class Pay
 * @package app\index\controller
 * @author goldeagle
 */
class Pay extends UserBase
{
    private $order_model;

    protected function _initialize()
    {
        parent::_initialize(); // TODO: Change the autogenerated stub
        $this->order_model = new OrderModel();
    }

    public function index()
    {
        exit("优车动力");
//        return $this->fetch();
    }

    /**
     * 使用微信支付SDK生成支付用的二维码
     * @param $orders
     * @return mixed
     */
    public function wxpayQRCode($orders)
    {
        $dataout = array(
            'code' => 1,
            'info' => '数据有误',
        );
        if (empty($orders)) {
            out_json_data($dataout);
        }
        $orders_arr = explode(',', $orders);
        $order = $this->order_model->get_pay_info($orders_arr, false, $this->customer_info['customer_id']);
        if (empty($order['pay_sn'])) {
            $dataout['code'] = 2;
            $dataout['info'] = '订单信息有误';
            out_json_data($dataout);
        }
        if (empty($order['total_price'])) {
            $dataout['code'] = 3;
            $dataout['info'] = '订单已使用余额完全支付';
            out_json_data($dataout);
        }
        //判断是否已经存在订单 url，如果已经存在且未超过2小时就使用旧的，否则生成新的
        $interval = date_diff(new \DateTime($order->update_time), new \DateTime());
        $h = $interval->format('%h');

        if (isset($order->pay_url) && $order->pay_url != '' && $h < 2) {
            $url = $order->pay_url;
        } else {
            $wxconfig = Config::get('wxconfig');
            $notify = new NativePay();
            $input = new WxPayUnifiedOrder();
            $input->setBody($order['body']);
            $input->setAttach("优车出行支付");
            $input->setOutTradeNo($order['pay_sn']);
            $input->setTotalFee(intval(floatval($order['total_price']) * 100));
            $input->setTimeStart(date("YmdHis"));
            $input->setTimeExpire(date("YmdHis", time() + 600));
            $input->setGoodsTag("QRCode");
            $input->setNotifyUrl($wxconfig['NOTIFY_URL']);
            $input->setTradeType("NATIVE");
            $input->setProductId($order['pay_sn']);
            $result = $notify->getPayUrl($input);
            $url = $result["code_url"];
            //保存订单标识
            $dataout['code'] = 0;
            $dataout['data'] = $url;
            $dataout['info'] = '二维码链接';
        }
        out_json_data($dataout);
    }

    /**
     * 微信支付使用 JSAPI
     * @param $orders
     * @param int $goods_type
     */
    public function wxpayJSAPI($orders, $goods_type = 1)
    {
        if (empty($goods_type)) {
            $goods_type = 1;
        }
        $dataout = array(
            'code' => 1,
            'info' => '数据有误',
        );
        if (empty($orders)) {
            out_json_data($dataout);
        }
        $orders_arr = explode(',', $orders);
        if (intval($goods_type) == GoodsType::$GoodsTypeCar['code']) {
            $order = $this->order_model->getPayInfo($orders_arr, false, $this->customer_info['customer_id']);
        } else if (intval($goods_type) == GoodsType::$GoodsTypeElectrocar['code']) {
            $order = $this->order_model->getElePayInfo($orders_arr, false, $this->customer_info['customer_id']);
        }
        if (empty($order['out_trade_no'])) {
            $dataout['code'] = 2;
            $dataout['info'] = '订单信息有误';
            out_json_data($dataout);
        }
        if (empty($order['total_price'])) {
            $dataout['code'] = 3;
            $dataout['info'] = '订单已使用余额完全支付';
            out_json_data($dataout);
        }
        $pay_sn = $order['out_trade_no'];
        //获取用户openid
        $tools = new JsApiPay();
        $openId = Session::get('wechar_openid');
        $wxconfig = Config::get('wxconfig');
        //统一下单
        $input = new WxPayUnifiedOrder();
        $input->setBody($order['body']);
        $input->setAttach("优车出行微信支付");
        $input->setOutTradeNo($order['out_trade_no']);
        $input->setTotalFee(intval(floatval($order['total_price']) * 100));
        $input->setTimeStart(date("YmdHis"));
        $input->setTimeExpire(date("YmdHis", time() + 3600));
        $input->setGoodsTag("Reward");
        $input->setNotifyUrl($wxconfig['NOTIFY_URL']);
        $input->setTradeType("JSAPI");
        $input->setOpenid($openId);
        $order = WxPayApi::unifiedOrder($input);

        if ($order['return_code'] == 'FAIL') {
            $dataout = array(
                'code' => 5,
                'info' => $order['return_msg']
            );
            out_json_data($dataout);
        }
        $jsApiParameters = $tools->getJsApiParameters($order);
        $dataout = array(
            'code' => 0,
            'pay_sn' => $pay_sn,
            'data' => json_decode($jsApiParameters),
            'info' => '获取成功'
        );
        out_json_data($dataout);
    }

    /**
     * 押金申请退款
     * @param $pay_sn
     */
    public function wxpayRefund($pay_sn)
    {
        $dataout = array(
            'code' => 33,
            'info' => '参数有误'
        );
        $customer_cash_model = new CustomerCash();
        if (empty($pay_sn)) {
            out_json_data($dataout);
        }
        $out_data = $customer_cash_model->is_return_cash($pay_sn);
        if ($out_data['code'] != 0) {
            out_json_data($out_data);
        }
        $map_cash = [
            'pay_sn' => $pay_sn,
            'payment_code' => "weixin",
            'state' => 20
        ];
        if (!empty($this->customer_info['customer_id'])) {
            $map_cash['customer_id'] = $this->customer_info['customer_id'];
        }
        $customer_cash_data = $customer_cash_model->where($map_cash)->field('pay_sn,cash')->find();
        if (empty($pay_sn) || empty($customer_cash_data)) {
            out_json_data($dataout);
        }
        $refund_sn = str_replace('cash', 'refund', $pay_sn);
        //退款订单
        $input = new WxPayRefund();
        $input->setOutTradeNo($customer_cash_data['pay_sn']);
        $input->setOutRefundNo($refund_sn);
        $input->setTotalFee(intval(floatval($customer_cash_data['cash']) * 100));
        $input->setRefundFee(intval(floatval($customer_cash_data['cash']) * 100));
        $input->setOpUserId("1501465161@1501465161");
        $order = WxPayApi::refund($input);
        if ($order['return_code'] == 'FAIL') {
            $dataout = array(
                'code' => 5,
                'info' => $order['return_msg']
            );
            out_json_data($dataout);
        } else if ($order['return_code'] == "SUCCESS" && $order['return_code'] == "SUCCESS") {
            $pay_info = array(
                'pay_sn' => $pay_sn,
                'refund_sn' => $refund_sn,
                'money' => floatval($customer_cash_data['cash']),
                'channel' => 2,
                'remark' => "微信支付 押金退款单号：" . $refund_sn,
            );
            if ($customer_cash_model->return_cash($pay_info, 'weixin')) {
//                Log::write('支付订单' . $order_info['out_trade_no'] . '状态更新成功');
                $dataout = array(
                    'code' => 0,
                    'info' => "退款申请成功"
                );
                out_json_data($dataout);
            }
        }
        $dataout = array(
            'code' => 20,
            'info' => "退款申请失败"
        );
        out_json_data($dataout);
    }

    /**
     * 微信押金支付使用 JSAPI
     * @param $pay_sn
     */
    public function wxcashJSAPI($pay_sn = '')
    {
        $dataout = array(
            'code' => 1,
            'info' => '参数有误',
        );
        if (empty($pay_sn)) {
            $dataout['code'] = 2;
            $dataout['info'] = '押金订单信息有误';
            out_json_data($dataout);
        }
        $cash_model = new CustomerCash();
        $cash_data = $cash_model->where(['pay_sn' => $pay_sn, 'customer_id' => $this->customer_info['customer_id']])->field('cash')->find();
        if (empty($cash_data)) {
            $dataout['code'] = 3;
            $dataout['info'] = '押金订单数据有误';
            out_json_data($dataout);
        }
        $price = $cash_data['cash'];
        //获取用户openid
        $tools = new JsApiPay();
        $openId = Session::get('wechar_openid');
        $wxconfig = Config::get('wxconfig');
        //统一下单
        $input = new WxPayUnifiedOrder();
        $input->setBody("使用微信支付押金");
        $input->setAttach("优车动力微信支付押金");
        $input->setOutTradeNo($pay_sn);
        $input->setTotalFee(intval(floatval($price) * 100));
        $input->setTimeStart(date("YmdHis"));
        $input->setTimeExpire(date("YmdHis", time() + 3600));
        $input->setGoodsTag("Reward");
        $input->setNotifyUrl($wxconfig['NOTIFY_URL_CASH']);
        $input->setTradeType("JSAPI");
        $input->setOpenid($openId);
        $order = WxPayApi::unifiedOrder($input);
        if ($order['return_code'] == 'FAIL') {
            $dataout = array(
                'code' => 5,
                'info' => $order['return_msg']
            );
            out_json_data($dataout);
        }
        $jsApiParameters = $tools->getJsApiParameters($order);
        $dataout = array(
            'code' => 0,
            'pay_sn' => $pay_sn,
            'data' => json_decode($jsApiParameters),
            'info' => '获取成功'
        );
        out_json_data($dataout);
    }

    /**
     * 微信充值支付余额使用 JSAPI
     * @param $price
     * @param $pay_sn
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     * @throws \wxpay\WxPayException
     */
    public function wxbalanceJSAPI($price, $pay_sn = '')
    {
        $dataout = array(
            'code' => 1,
            'info' => '参数有误',
        );
        if (empty($price)) {
            out_json_data($dataout);
        }
        $customer_balance_model = new CustomerBalance();
        if (empty($pay_sn)) {
            $balance_info = array(
                'customer_id' => $this->customer_info['customer_id'],
                'balance' => floatval($price),
                'remark' => "微信充值平台余额:" . $price . "(元)"
            );
            $pay_info = $customer_balance_model->add_balance($balance_info);
            $pay_sn = $pay_info['out_trade_no'];
            $price = $pay_info['total_price'];
        } else {
            $map = array(
                'customer_id' => $this->customer_info['customer_id'],
                'pay_sn' => $pay_sn
            );
            $balance_data = $customer_balance_model->where($map)->find();
            if (empty($balance_data)) {
                $balance_info = array(
                    'customer_id' => $this->customer_info['customer_id'],
                    'balance' => floatval($price),
                    'remark' => "微信充值平台余额:" . $price . "(元)"
                );
                $pay_info = $customer_balance_model->add_balance($balance_info);
                $pay_sn = $pay_info['out_trade_no'];
                $price = $pay_info['total_price'];
            } else {
                $balance_data->balance = floatval($price);
                $balance_data->save();
            }
        }
        if (empty($pay_sn)) {
            $dataout['code'] = 2;
            $dataout['info'] = '充值订单信息有误';
            out_json_data($dataout);
        }
        if (empty($price)) {
            $dataout['code'] = 3;
            $dataout['info'] = '充值金额有误';
            out_json_data($dataout);
        }
        //获取用户openid
        $tools = new JsApiPay();
        $openId = Session::get('wechar_openid');
        $wxconfig = Config::get('wxconfig');
        //统一下单
        $input = new WxPayUnifiedOrder();
        $input->setBody("充值平台余额");
        $input->setAttach("优车动力微信支付");
        $input->setOutTradeNo($pay_sn);
        $input->setTotalFee(intval(floatval($price) * 100));
        $input->setTimeStart(date("YmdHis"));
        $input->setTimeExpire(date("YmdHis", time() + 3600));
        $input->setGoodsTag("Reward");
        $input->setNotifyUrl($wxconfig['NOTIFY_URL_BALANCE']);
        $input->setTradeType("JSAPI");
        $input->setOpenid($openId);
        $order = WxPayApi::unifiedOrder($input);

        if ($order['return_code'] == 'FAIL') {
            $dataout = array(
                'code' => 5,
                'info' => $order['return_msg']
            );
            out_json_data($dataout);
        }
        $jsApiParameters = $tools->getJsApiParameters($order);
        $dataout = array(
            'code' => 0,
            'pay_sn' => $pay_sn,
            'data' => json_decode($jsApiParameters),
            'info' => '获取成功'
        );
        out_json_data($dataout);
    }

    /**
     * 微信支付使用 MWEB 微信以外的浏览器
     * @return mixed
     */
    public function wxpayMWEB($orders, $goods_type = 1)
    {
        if (empty($goods_type)) {
            $goods_type = 1;
        }
        $dataout = array(
            'code' => 1,
            'info' => '数据有误',
        );
        if (empty($orders)) {
            out_json_data($dataout);
        }
        $orders_arr = explode(',', $orders);
        if (intval($goods_type) == GoodsType::$GoodsTypeCar['code']) {
            $order = $this->order_model->getPayInfo($orders_arr, false, $this->customer_info['customer_id']);
        } else if (intval($goods_type) == GoodsType::$GoodsTypeElectrocar['code']) {
            $order = $this->order_model->getElePayInfo($orders_arr, false, $this->customer_info['customer_id']);
        }
        if (empty($order['out_trade_no'])) {
            $dataout['code'] = 2;
            $dataout['info'] = '订单信息有误';
            out_json_data($dataout);
        }
        if (empty($order['total_price'])) {
            $dataout['code'] = 3;
            $dataout['info'] = '订单已使用余额完全支付';
            out_json_data($dataout);
        }
        $pay_sn = $order['out_trade_no'];
        $wxconfig = Config::get('wxconfig');
        //统一下单
        $input = new WxPayUnifiedOrder();
        $input->setBody($order['body']);
        $input->setAttach("优车出行微信支付");
        $input->setOutTradeNo($order['out_trade_no']);
        $input->setTotalFee(intval(floatval($order['total_price']) * 100));
        $input->setTimeStart(date("YmdHis"));
        $input->setTimeExpire(date("YmdHis", time() + 600));
        $input->setGoodsTag("Reward");
        $input->setNotifyUrl($wxconfig['NOTIFY_URL']);
        $input->setTradeType("MWEB");
        $order = WxPayApi::unifiedOrder($input);
        if ($order['return_code'] == 'FAIL') {
            $dataout = array(
                'code' => 5,
                'info' => $order['return_msg']
            );
            out_json_data($dataout);
        }
        $dataout = array(
            'code' => 0,
            'url' => $order['mweb_url'] . "&redirect_url=" . urlencode($wxconfig['QUERY_URL'] . "/pay_sn/" . $pay_sn . ".html"),
            'info' => '获取成功'
        );
        out_json_data($dataout);
    }

    /**
     * APP 获取支付签名
     * @param string $body //商品描述
     * @param string $detail //商品标题
     * @param string $out_trade_no //订单号
     * @param string $total_fee //订单总金额
     * @throws \wxpay\WxPayException
     */
    public function appSign($body = '', $detail = '', $out_trade_no = '', $total_fee = '')
    {
        $out_data = [
            'code' => 100,
            'info' => '参数有误'
        ];
        if (empty($body) || empty($detail) || empty($out_trade_no) || empty($total_fee)) {
            out_json_data($out_data);
        }
        $params['body'] = $body; //商品描述
        $params['detail'] = $detail; //商品详情
        $params['out_trade_no'] = $out_trade_no; //自定义的订单号
        $params['total_fee'] = $total_fee; //订单金额 只能为整数 单位为分
        $params['trade_type'] = 'APP'; //交易类型 JSAPI | NATIVE | APP | WAP

        $wxconfig = Config::get('wxconfig');
        $tools = new AppApiPay();
        $input = new WxPayUnifiedOrder();
        $input->setBody($body);
        $input->setAttach("优车出行微信支付");
        $input->setOutTradeNo($out_trade_no);
        $input->setTotalFee(intval(floatval($total_fee) * 100));
        $input->setTimeStart(date("YmdHis"));
        $input->setTimeExpire(date("YmdHis", time() + 3600));
        $input->setGoodsTag("Reward");
        $input->setNotifyUrl($wxconfig['NOTIFY_URL']);
        $input->setTradeType("APP");
        $order = WxPayApi::unifiedOrder($input);
        if ($order['return_code'] == 'FAIL') {
            $out_data = array(
                'code' => 5,
                'info' => $order['return_msg']
            );
            out_json_data($out_data);
        }
        $AppApiParameters = $tools->getAppParameters($order);
        $out_data['code'] = 0;
        $out_data['info'] = '获取成功';
        $out_data['data'] = $AppApiParameters;
        out_json_data($out_data);
    }
}