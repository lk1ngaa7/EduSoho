<?php
namespace Topxia\MobileBundleV2\Processor\Impl;

use Topxia\MobileBundleV2\Processor\BaseProcessor;
use Topxia\MobileBundleV2\Processor\OrderProcessor;
use Topxia\MobileBundleV2\Alipay\MobileAlipayConfig;

class OrderProcessorImpl extends BaseProcessor implements OrderProcessor
{

    public function coinPayNotify()
    {
        $payType = $this->getParam("payType");
        $amount = $this->getParam("amount", 0);
        $paidTime = $this->getParam("paidTime", 0);
        $sn = $this->getParam("sn");
        $status = $this->getParam("status");

        if (empty($sn) || empty($status)) {
            return $this->createErrorResponse('error', "订单数据缺失，充值失败！");
        }

        if ($payType == "iap") {
            $payData = array(
                "amount"=>$amount,
                "sn"=>$sn,
                "status"=>$status,
                "paidTime"=>$paidTime
            );
            try {
                list($success, $order) = $this->getCashOrdersService()->payOrder($payData);
                return true;
            } catch (\Exception $e) {
                return $this->createErrorResponse('error', $e->getMessage());
            }
        }
        
        return false;
    }

    public function buyCoin()
    {
        $user = $this->controller->getUserByToken($this->request);
        if (empty($user)) {
            return $this->createErrorResponse('not_login', "您尚未登录！");
        }

        $payType = $this->getParam("payType");
        $amount = $this->getParam("amount", 0);

        $formData['payment'] = $payType;
        $formData['userId'] = $user->id;
        $formData['amount'] = $amount;

        $order = $this->getCashOrdersService()->addOrder($formData);

        if (empty($order)) {
            return $this->createErrorResponse('error', "充值失败！"); 
        }

        return $order;
    }

    /**
    * payType iap, online
    */
    public function payCourse()
    {
        $payType = $this->getParam("payType", "online");
        $courseId = $this->getParam('courseId');
        if (empty($courseId)) {
            return $this->createErrorResponse('not_courseId', '没有找到加入的课程信息！');
        }
        $token = $this->controller->getUserToken($this->request);
        $user = $this->controller->getUser();
        if (!$user->isLogin()) {
            return $this->createErrorResponse('not_login', '用户未登录，加入学习失败！');
        }

        $this->formData['courseId'] = $courseId;
        $coinRate = $this->controller->setting('coin.cash_rate');
        if (!isset($coinRate)) {
            $coinRate = 1;
        }

        $course = $this->controller->getCourseService()->getCourse($courseId);
        $order = array();
        $order['targetId'] = $courseId;
        $order['targetType'] = 'course';
        $order['payment'] = 'alipay';
        $order['amount'] = $course['price'];
        $order['priceType'] = 'RMB';
        $order['totalPrice'] = $course['price'];
        $order['coinRate'] = $coinRate;
        $order['coinAmount'] = 0;

        if ($payType == 'iap') {
            return $this->payCourseByIAP($order, $user->id);
        }

        $order = $this->controller->getCourseOrderService()->createOrder($order);
        if ($order['status'] == 'paid') {
            return array('status' => 'ok', 'paid' => true, 'message' => '', 'payUrl' => '');
        }

        return $this->payCourseByAlipay($order, $token);
    }

    private function payCourseByIAP($order, $userId)
    {
        $result = array('status' => 'error', 'message' => '', 'paid' => false, 'payUrl' => '');
        $coinType= $this->getSettingService()->get('coin',array());
        $coinEnabled = $coinType['coin_enabled'];
        if(empty($coinEnabled) || $coinEnabled == 0) {
            $result['message'] = '网校虚拟币未开启！';
            return $result;
        }

        $account = $this->getCashAccountService()->getAccountByUserId($userId, true);
        if(empty($account)){
            $account = $this->getCashAccountService()->createAccount($userId);
        }

        $cash = (float) $account['cash'] / (float) $coinType['cash_rate'];
        $price = (float) $order['totalPrice'];
        if ($cash < $price) {
            $result['message'] = '账户余额不够';
            return $result;
        }

        $order['coinAmount' ] = (string)((float)$price * (float) $coinType['cash_rate']);
        $order['priceType'] = $coinType['price_type'];
        $order['amount'] = 0;
        $order['userId'] = $userId;
        $order['coinRate'] = $coinType['cash_rate'];

        $order = $this->controller->getCourseOrderService()->createOrder($order);
        list($success, $order)= $this->processorOrder($order);
        if ($success && $order['status'] == 'paid') {
            $result['status'] = 'ok';
        } else {
            $result['message'] = '支付失败!';
        }

        return $result;
    }

    private function processorOrder($order)
    {
        $success = false;
        if($order["amount"] == 0 && $order["coinAmount"] == 0) {
            $payData = array(
                'sn' => $order['sn'],
                'status' => 'success', 
                'amount' => $order['amount'], 
                'paidTime' => time()
            );
            list($success, $order) = $this->getPayCenterService()->processOrder($payData);
        } else if ($order["amount"] == 0 && $order["coinAmount"] > 0) {
            $payData = array(
                'sn' => $order['sn'],
                'status' => 'success', 
                'amount' => $order['amount'], 
                'paidTime' => time()
            );
            list($success, $order) = $this->getPayCenterService()->pay($payData);
        }

        return array($success, $order);
    }

    private function payCourseByAlipay($order, $token)
    {
        $result = array('status' => 'error', 'message' => '', 'paid' => false, 'payUrl' => '');
        $payment = $this->controller->setting('payment', array());
        if (empty($payment['enabled'])) {
            $result['message'] = '支付功能未开启！';
            return $result;
        }
        if (empty($payment['alipay_enabled'])) {
            $result['message'] = '支付功能未开启！';
            return $result;
        }
        if (empty($payment['alipay_key']) or empty($payment['alipay_secret']) or empty($payment['alipay_account'])) {
            $result['message'] = '支付宝参数不正确！';
            return $result;
        }
        if (empty($payment['alipay_type']) or $payment['alipay_type'] != 'direct') {
            $payUrl = $this->controller->generateUrl('mapi_order_submit_pay_request', array('id' => $order['id'], 'token' => $token), true);
            $result['payUrl'] = $payUrl . '?token=' . $token;
        } else {
            $result['payUrl'] = MobileAlipayConfig::createAlipayOrderUrl($this->request, 'edusoho', $order);
        }
        $result['status'] = 'ok';
        return $result;
    }
}