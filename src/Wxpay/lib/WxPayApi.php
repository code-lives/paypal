<?php

namespace wxalipay\paypal\Wxpay\lib;

/**
 *
 * 接口访问类，包含所有微信支付API列表的封装，类中方法为static方法，
 * 每个接口有默认超时时间（除提交被扫支付为10s，上报超时时间为1s外，其他均为6s）
 * @author widyhu
 *
 */
class WxPayApi
{
    /**
     *
     * 统一下单，WxPayUnifiedOrder中out_trade_no、body、total_fee、trade_type必填
     * appid、mchid、spbill_create_ip、nonce_str不需要填入
     *
     * @param     $inputObj
     * @param int $timeOut
     *
     * @return array
     */
    public static function unifiedOrder($inputObj, $timeOut = 6)
    {
        $url = "https://api.mch.weixin.qq.com/pay/unifiedorder";
        //检测必填参数
        if (!$inputObj->IsOut_trade_noSet()) {
            return ['return_code' => 'FAIL', 'return_msg' => '缺少统一支付接口必填参数out_trade_no', 'input' => $inputObj];
        } else if (!$inputObj->IsBodySet()) {
            return ['return_code' => 'FAIL', 'return_msg' => '缺少统一支付接口必填参数body', 'input' => $inputObj];
        } else if (!$inputObj->IsTotal_feeSet()) {
            return ['return_code' => 'FAIL', 'return_msg' => '缺少统一支付接口必填参数total_fee', 'input' => $inputObj];
        } else if (!$inputObj->IsTrade_typeSet()) {
            return ['return_code' => 'FAIL', 'return_msg' => '缺少统一支付接口必填参数trade_type', 'input' => $inputObj];
        } else if (!$inputObj->IsNotify_urlSet()) {
            return ['return_code' => 'FAIL', 'return_msg' => '缺少统一支付接口必填参数nitify_url', 'input' => $inputObj];
        }

        //关联参数
        if ($inputObj->GetTrade_type() == "JSAPI" && !$inputObj->IsOpenidSet()) {
            return ['return_code' => 'FAIL', 'return_msg' => '缺少统一支付接口必填参数openid！trade_type为JSAPI时，openid为必填参数！', 'input' => $inputObj];
        }
        if ($inputObj->GetTrade_type() == "NATIVE" && !$inputObj->IsProduct_idSet()) {
            return ['return_code' => 'FAIL', 'return_msg' => '缺少统一支付接口必填参数product_id！trade_type为NATIVE时，product_id为必填参数！', 'input' => $inputObj];
        }

        $inputObj->SetAppid(APPID); //公众账号ID
        $inputObj->SetMch_id(MCHID); //商户号
        $inputObj->SetSpbill_create_ip($_SERVER['REMOTE_ADDR']); //终端ip
        $inputObj->SetNonce_str(self::getNonceStr()); //随机字符串

        //签名
        $inputObj->SetSign();
        $xml = $inputObj->ToXml();

        $startTimeStamp = self::getMillisecond(); //请求开始时间
        $response = self::postXmlCurl($xml, $url, false, $timeOut);
        $result = $inputObj->Init($response);
        self::reportCostTime($url, $startTimeStamp, $result); //上报请求花费时间

        return $result;
    }

    /**
     *
     * 产生随机字符串，不长于32位
     *
     * @param int $length
     *
     * @return 产生的随机字符串
     */
    public static function getNonceStr($length = 32)
    {
        $chars = "abcdefghijklmnopqrstuvwxyz0123456789";
        $str = "";
        for ($i = 0; $i < $length; $i++) {
            $str .= substr($chars, mt_rand(0, strlen($chars) - 1), 1);
        }

        return $str;
    }

    /**
     * 获取毫秒级别的时间戳
     */
    private static function getMillisecond()
    {
        //获取毫秒的时间戳
        $time = explode(" ", microtime());
        $time = $time[1] . ($time[0] * 1000);
        $time2 = explode(".", $time);
        $time = $time2[0];

        return $time;
    }

    /**
     * 以post方式提交xml到对应的接口url
     *
     * @param string $xml 需要post的xml数据
     * @param string $url
     * @param bool $useCert 是否需要证书，默认不需要
     * @param int $second url执行超时时间，默认30s
     *
     * @return mixed|string
     */
    private static function postXmlCurl($xml, $url, $useCert = false, $second = 30)
    {
        $ch = curl_init();
        //设置超时
        curl_setopt($ch, CURLOPT_TIMEOUT, $second);

        //如果有配置代理这里就设置代理
        if (
            CURL_PROXY_HOST != "0.0.0.0"
            && CURL_PROXY_PORT != 0
        ) {
            curl_setopt($ch, CURLOPT_PROXY, CURL_PROXY_HOST);
            curl_setopt($ch, CURLOPT_PROXYPORT, CURL_PROXY_PORT);
        }
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); //严格校验
        //设置header
        curl_setopt($ch, CURLOPT_HEADER, false);
        //要求结果为字符串且输出到屏幕上
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        if ($useCert == true) {
            //设置证书
            //使用证书：cert 与 key 分别属于两个.pem文件
            curl_setopt($ch, CURLOPT_SSLCERTTYPE, 'PEM');
            curl_setopt($ch, CURLOPT_SSLCERT, SSLCERT_PATH);
            curl_setopt($ch, CURLOPT_SSLKEYTYPE, 'PEM');
            curl_setopt($ch, CURLOPT_SSLKEY, SSLKEY_PATH);
        }
        //post提交方式
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);
        //运行curl
        $data = curl_exec($ch);
        //返回结果

        if ($data) {
            curl_close($ch);

            return $data;
        } else {
            $error = curl_errno($ch);
            curl_close($ch);

            $msg = 'curl出错，错误码:' . $error;

            $error_arr = [
                'return_code' => 'fail',
                'result_code' => 'fail',
                'err_code' => $msg,
                'return_msg' => $msg,
            ];

            return self::ToXml($error_arr);
        }
    }

    /**
     * 将数组转换成xml
     *
     * @param $error_arr
     *
     * @return string
     */
    private static function ToXml($param)
    {

        $xml = "<xml>";
        foreach ($param as $key => $val) {
            if (is_numeric($val)) {
                $xml .= "<" . $key . ">" . $val . "</" . $key . ">";
            } else {
                $xml .= "<" . $key . "><![CDATA[" . $val . "]]></" . $key . ">";
            }
        }
        $xml .= "</xml>";

        return $xml;
    }

    /**
     *
     * 上报数据， 上报的时候将屏蔽所有异常流程
     *
     * @param string $usrl
     * @param int $startTimeStamp
     * @param array $data
     */
    private static function reportCostTime($url, $startTimeStamp, $data)
    {
        //如果不需要上报数据
        if (REPORT_LEVENL == 0) {
            return;
        }
        //如果仅失败上报
        if (
            REPORT_LEVENL == 1
            && array_key_exists("return_code", $data)
            && $data["return_code"] == "SUCCESS"
            && array_key_exists("result_code", $data)
            && $data["result_code"] == "SUCCESS"
        ) {
            return;
        }

        //上报逻辑
        $endTimeStamp = self::getMillisecond();
        $objInput = new WxPayDataBase();
        $objInput->SetInterface_url($url);
        $objInput->SetExecute_time_($endTimeStamp - $startTimeStamp);
        //返回状态码
        if (array_key_exists("return_code", $data)) {
            $objInput->SetReturn_code($data["return_code"]);
        }
        //返回信息
        if (array_key_exists("return_msg", $data)) {
            $objInput->SetReturn_msg($data["return_msg"]);
        }
        //业务结果
        if (array_key_exists("result_code", $data)) {
            $objInput->SetResult_code($data["result_code"]);
        }
        //错误代码
        if (array_key_exists("err_code", $data)) {
            $objInput->SetErr_code($data["err_code"]);
        }
        //错误代码描述
        if (array_key_exists("err_code_des", $data)) {
            $objInput->SetErr_code_des($data["err_code_des"]);
        }
        //商户订单号
        if (array_key_exists("out_trade_no", $data)) {
            $objInput->SetOut_trade_no($data["out_trade_no"]);
        }
        //设备号
        if (array_key_exists("device_info", $data)) {
            $objInput->SetDevice_info($data["device_info"]);
        }

        self::report($objInput);
    }

    /**
     *
     * 测速上报，该方法内部封装在report中，使用时请注意异常流程
     * WxPayReport中interface_url、return_code、result_code、user_ip、execute_time_必填
     * appid、mchid、spbill_create_ip、nonce_str不需要填入
     *
     * @param WxPayReport $inputObj
     * @param int $timeOut
     *
     * @return 成功时返回，其他抛异常
     */
    public static function report($inputObj, $timeOut = 1)
    {
        $url = "https://api.mch.weixin.qq.com/payitil/report";
        //检测必填参数
        if (!$inputObj->IsInterface_urlSet()) {
            return ['return_code' => 'FAIL', 'return_msg' => '接口URL，缺少必填参数interface_url！', 'input' => $inputObj];
        }
        if (!$inputObj->IsReturn_codeSet()) {
            return ['return_code' => 'FAIL', 'return_msg' => '接口URL，缺少必填参数interface_url！', 'input' => $inputObj];
        }
        if (!$inputObj->IsResult_codeSet()) {
            return ['return_code' => 'FAIL', 'return_msg' => '业务结果，缺少必填参数result_code！', 'input' => $inputObj];
        }
        if (!$inputObj->IsUser_ipSet()) {
            return ['return_code' => 'FAIL', 'return_msg' => '访问接口IP，缺少必填参数user_ip！', 'input' => $inputObj];
        }
        if (!$inputObj->IsExecute_time_Set()) {
            return ['return_code' => 'FAIL', 'return_msg' => '接口耗时，缺少必填参数execute_time_！', 'input' => $inputObj];
        }
        $inputObj->SetAppid(APPID); //公众账号ID
        $inputObj->SetMch_id(MCHID); //商户号
        $inputObj->SetUser_ip($_SERVER['REMOTE_ADDR']); //终端ip
        $inputObj->SetTime(date("YmdHis")); //商户上报时间
        $inputObj->SetNonce_str(self::getNonceStr()); //随机字符串

        $inputObj->SetSign(); //签名
        $xml = $inputObj->ToXml();

        $startTimeStamp = self::getMillisecond(); //请求开始时间
        $response = self::postXmlCurl($xml, $url, false, $timeOut);

        return $response;
    }

    /**
     *
     * 查询订单，WxPayOrderQuery中out_trade_no、transaction_id至少填一个
     * appid、mchid、spbill_create_ip、nonce_str不需要填入
     *
     * @param WxPayOrderQuery $inputObj
     * @param int $timeOut
     *
     * @return array
     */
    public static function orderQuery($inputObj, $timeOut = 6)
    {
        $url = "https://api.mch.weixin.qq.com/pay/orderquery";
        //检测必填参数
        if (!$inputObj->IsOut_trade_noSet() && !$inputObj->IsTransaction_idSet()) {
            return ['return_code' => 'FAIL', 'return_msg' => '订单查询接口中，out_trade_no、transaction_id至少填一个！', 'input' => $inputObj];
        }
        $inputObj->SetAppid(APPID); //公众账号ID
        $inputObj->SetMch_id(MCHID); //商户号
        $inputObj->SetNonce_str(self::getNonceStr()); //随机字符串

        $inputObj->SetSign(); //签名
        $xml = $inputObj->ToXml();
        $startTimeStamp = self::getMillisecond(); //请求开始时间
        $response = self::postXmlCurl($xml, $url, false, $timeOut);
        $result = $inputObj->Init($response);
        self::reportCostTime($url, $startTimeStamp, $result); //上报请求花费时间

        return $result;
    }

    /**
     *
     * 关闭订单，WxPayCloseOrder中out_trade_no必填
     * appid、mchid、spbill_create_ip、nonce_str不需要填入
     *
     * @param WxPayCloseOrder $inputObj
     * @param int $timeOut
     *
     * @return array
     */
    public static function closeOrder($inputObj, $timeOut = 6)
    {
        $url = "https://api.mch.weixin.qq.com/pay/closeorder";
        //检测必填参数
        if (!$inputObj->IsOut_trade_noSet()) {
            return ['return_code' => 'FAIL', 'return_msg' => '订单查询接口中，out_trade_no必填！', 'input' => $inputObj];
        }
        $inputObj->SetAppid(APPID); //公众账号ID
        $inputObj->SetMch_id(MCHID); //商户号
        $inputObj->SetNonce_str(self::getNonceStr()); //随机字符串

        $inputObj->SetSign(); //签名
        $xml = $inputObj->ToXml();

        $startTimeStamp = self::getMillisecond(); //请求开始时间
        $response = self::postXmlCurl($xml, $url, true, $timeOut);
        $result = $inputObj->Init($response);
        self::reportCostTime($url, $startTimeStamp, $result); //上报请求花费时间

        return $result;
    }

    /**
     *
     * 申请退款，WxPayRefund中out_trade_no、transaction_id至少填一个且
     * out_refund_no、total_fee、refund_fee、op_user_id为必填参数
     * appid、mchid、spbill_create_ip、nonce_str不需要填入
     *
     * @param WxPayRefund $inputObj
     * @param int $timeOut
     *
     * @return array
     */
    public static function refund($inputObj, $timeOut = 6)
    {
        $url = "https://api.mch.weixin.qq.com/secapi/pay/refund";
        //检测必填参数
        if (!$inputObj->IsOut_trade_noSet() && !$inputObj->IsTransaction_idSet()) {
            return ['return_code' => 'FAIL', 'return_msg' => '退款申请接口中，out_trade_no、transaction_id至少填一个！', 'input' => $inputObj];
        } else if (!$inputObj->IsOut_refund_noSet()) {
            return ['return_code' => 'FAIL', 'return_msg' => '退款申请接口中，缺少必填参数out_refund_no！', 'input' => $inputObj];
        } else if (!$inputObj->IsTotal_feeSet()) {
            return ['return_code' => 'FAIL', 'return_msg' => '退款申请接口中，缺少必填参数total_fee！', 'input' => $inputObj];
        } else if (!$inputObj->IsRefund_feeSet()) {
            return ['return_code' => 'FAIL', 'return_msg' => '退款申请接口中，缺少必填参数refund_fee！', 'input' => $inputObj];
        } else if (!$inputObj->IsOp_user_idSet()) {
            return ['return_code' => 'FAIL', 'return_msg' => '退款申请接口中，缺少必填参数op_user_id！', 'input' => $inputObj];
        }
        $inputObj->SetAppid(APPID); //公众账号ID
        $inputObj->SetMch_id(MCHID); //商户号
        $inputObj->SetNonce_str(self::getNonceStr()); //随机字符串

        $inputObj->SetSign(); //签名
        $xml = $inputObj->ToXml();
        $startTimeStamp = self::getMillisecond(); //请求开始时间

        $response = self::postXmlCurl($xml, $url, true, $timeOut);

        $result = $inputObj->Init($response);
        self::reportCostTime($url, $startTimeStamp, $result); //上报请求花费时间

        return $result;
    }

    /**
     *
     * 商户支付，WxPayRefund中out_trade_no、transaction_id至少填一个且
     * out_refund_no、total_fee、refund_fee、op_user_id为必填参数
     * appid、mchid、spbill_create_ip、nonce_str不需要填入
     *
     * @param WxPayRefund $inputObj
     * @param int $timeOut
     *
     * @return array
     */
    public static function transfers($inputObj, $timeOut = 6)
    {
        $url = "https://api.mch.weixin.qq.com/mmpaymkttransfers/promotion/transfers";

        $inputObj->mch_appid(MCHAPPID); //公众账号ID
        $inputObj->mchMch_id(MCHID); //商户号
        $inputObj->SetNonce_str(self::getNonceStr()); //随机字符串

        $inputObj->SetSign(); //签名
        $xml = $inputObj->ToXml();

        $startTimeStamp = self::getMillisecond(); //请求开始时间
        $response = self::postXmlCurl($xml, $url, true, $timeOut);

        $result = $inputObj->Init($response);

        self::reportCostTime($url, $startTimeStamp, $result); //上报请求花费时间

        return $result;
    }

    /**
     *
     * 查询退款
     * 提交退款申请后，通过调用该接口查询退款状态。退款有一定延时，
     * 用零钱支付的退款20分钟内到账，银行卡支付的退款3个工作日后重新查询退款状态。
     * WxPayRefundQuery中out_refund_no、out_trade_no、transaction_id、refund_id四个参数必填一个
     * appid、mchid、spbill_create_ip、nonce_str不需要填入
     *
     * @param WxPayRefundQuery $inputObj
     * @param int $timeOut
     *
     * @return array
     */
    public static function refundQuery($inputObj, $timeOut = 6)
    {
        $url = "https://api.mch.weixin.qq.com/pay/refundquery";
        //检测必填参数
        if (
            !$inputObj->IsOut_refund_noSet()
            && !$inputObj->IsOut_trade_noSet()
            && !$inputObj->IsTransaction_idSet()
            && !$inputObj->IsRefund_idSet()
        ) {
            return ['return_code' => 'FAIL', 'return_msg' => '退款查询接口中，out_refund_no、out_trade_no、transaction_id、refund_id四个参数必填一个！', 'input' => $inputObj];
        }
        $inputObj->SetAppid(APPID); //公众账号ID
        $inputObj->SetMch_id(MCHID); //商户号
        $inputObj->SetNonce_str(self::getNonceStr()); //随机字符串

        $inputObj->SetSign(); //签名
        $xml = $inputObj->ToXml();

        $startTimeStamp = self::getMillisecond(); //请求开始时间
        $response = self::postXmlCurl($xml, $url, false, $timeOut);
        $result = $inputObj->Init($response);
        self::reportCostTime($url, $startTimeStamp, $result); //上报请求花费时间

        return $result;
    }

    /**
     * 下载对账单，WxPayDownloadBill中bill_date为必填参数
     * appid、mchid、spbill_create_ip、nonce_str不需要填入
     *
     * @param WxPayDownloadBill $inputObj
     * @param int $timeOut
     *
     * @return array
     */
    public static function downloadBill($inputObj, $timeOut = 6)
    {
        $url = "https://api.mch.weixin.qq.com/pay/downloadbill";
        //检测必填参数
        if (!$inputObj->IsBill_dateSet()) {
            return ['return_code' => 'FAIL', 'return_msg' => '对账单接口中，缺少必填参数bill_date！', 'input' => $inputObj];
        }
        $inputObj->SetAppid(APPID); //公众账号ID
        $inputObj->SetMch_id(MCHID); //商户号
        $inputObj->SetNonce_str(self::getNonceStr()); //随机字符串

        $inputObj->SetSign(); //签名
        $xml = $inputObj->ToXml();

        $response = self::postXmlCurl($xml, $url, false, $timeOut);
        if (substr($response, 0, 5) == "<xml>") {
            return "";
        }

        return $response;
    }

    /**
     * 提交被扫支付API
     * 收银员使用扫码设备读取微信用户刷卡授权码以后，二维码或条码信息传送至商户收银台，
     * 由商户收银台或者商户后台调用该接口发起支付。
     * WxPayWxPayMicroPay中body、out_trade_no、total_fee、auth_code参数必填
     * appid、mchid、spbill_create_ip、nonce_str不需要填入
     *
     * @param WxPayWxPayMicroPay $inputObj
     * @param int $timeOut
     */
    public static function micropay($inputObj, $timeOut = 10)
    {
        $url = "https://api.mch.weixin.qq.com/pay/micropay";
        //检测必填参数
        if (!$inputObj->IsBodySet()) {
            return ['return_code' => 'FAIL', 'return_msg' => '提交被扫支付API接口中，缺少必填参数body！', 'input' => $inputObj];
        } else if (!$inputObj->IsOut_trade_noSet()) {
            return ['return_code' => 'FAIL', 'return_msg' => '提交被扫支付API接口中，缺少必填参数out_trade_no！', 'input' => $inputObj];
        } else if (!$inputObj->IsTotal_feeSet()) {
            return ['return_code' => 'FAIL', 'return_msg' => '提交被扫支付API接口中，缺少必填参数total_fee！', 'input' => $inputObj];
        } else if (!$inputObj->IsAuth_codeSet()) {
            return ['return_code' => 'FAIL', 'return_msg' => '提交被扫支付API接口中，缺少必填参数auth_code！', 'input' => $inputObj];
        }

        $inputObj->SetSpbill_create_ip($_SERVER['REMOTE_ADDR']); //终端ip
        $inputObj->SetAppid(APPID); //公众账号ID
        $inputObj->SetMch_id(MCHID); //商户号
        $inputObj->SetNonce_str(self::getNonceStr()); //随机字符串

        $inputObj->SetSign(); //签名
        $xml = $inputObj->ToXml();

        $startTimeStamp = self::getMillisecond(); //请求开始时间
        $response = self::postXmlCurl($xml, $url, false, $timeOut);
        $result = $inputObj->Init($response);
        self::reportCostTime($url, $startTimeStamp, $result); //上报请求花费时间

        return $result;
    }

    /**
     * 撤销订单API接口，WxPayReverse中参数out_trade_no和transaction_id必须填写一个
     * appid、mchid、spbill_create_ip、nonce_str不需要填入
     *
     * @param     $inputObj
     * @param int $timeOut
     *
     * @return array
     */
    public static function reverse($inputObj, $timeOut = 6)
    {
        $url = "https://api.mch.weixin.qq.com/secapi/pay/reverse";
        //检测必填参数
        if (!$inputObj->IsOut_trade_noSet() && !$inputObj->IsTransaction_idSet()) {
            return ['return_code' => 'FAIL', 'return_msg' => '撤销订单API接口中，参数out_trade_no和transaction_id必须填写一个！', 'input' => $inputObj];
        }

        $inputObj->SetAppid(APPID); //公众账号ID
        $inputObj->SetMch_id(MCHID); //商户号
        $inputObj->SetNonce_str(self::getNonceStr()); //随机字符串

        $inputObj->SetSign(); //签名
        $xml = $inputObj->ToXml();

        $startTimeStamp = self::getMillisecond(); //请求开始时间
        $response = self::postXmlCurl($xml, $url, true, $timeOut);
        $result = $inputObj->Init($response);
        self::reportCostTime($url, $startTimeStamp, $result); //上报请求花费时间

        return $result;
    }

    /**
     *
     * 生成二维码规则,模式一生成支付二维码
     * appid、mchid、spbill_create_ip、nonce_str不需要填入
     *
     * @param     $inputObj
     * @param int $timeOut
     *
     * @return array
     */
    public static function bizpayurl($inputObj, $timeOut = 6)
    {
        if (!$inputObj->IsProduct_idSet()) {
            return ['return_code' => 'FAIL', 'return_msg' => '生成二维码，缺少必填参数product_id！', 'input' => $inputObj];
        }

        $inputObj->SetAppid(APPID); //公众账号ID
        $inputObj->SetMch_id(MCHID); //商户号
        $inputObj->SetTime_stamp(time()); //时间戳
        $inputObj->SetNonce_str(self::getNonceStr()); //随机字符串

        $inputObj->SetSign(); //签名

        return $inputObj->GetValues();
    }

    /**
     *
     * 转换短链接
     * 该接口主要用于扫码原生支付模式一中的二维码链接转成短链接(weixin://wxpay/s/XXXXXX)，
     * 减小二维码数据量，提升扫描速度和精确度。
     * appid、mchid、spbill_create_ip、nonce_str不需要填入
     *
     * @param     $inputObj
     * @param int $timeOut
     *
     * @return array
     */
    public static function shorturl($inputObj, $timeOut = 6)
    {
        $url = "https://api.mch.weixin.qq.com/tools/shorturl";
        //检测必填参数
        if (!$inputObj->IsLong_urlSet()) {
            return ['return_code' => 'FAIL', 'return_msg' => '需要转换的Url为空', 'input' => $inputObj];
        }
        $inputObj->SetAppid(APPID); //公众账号ID
        $inputObj->SetMch_id(MCHID); //商户号
        $inputObj->SetNonce_str(self::getNonceStr()); //随机字符串

        $inputObj->SetSign(); //签名
        $xml = $inputObj->ToXml();

        $startTimeStamp = self::getMillisecond(); //请求开始时间
        $response = self::postXmlCurl($xml, $url, false, $timeOut);
        $result = $inputObj->Init($response);
        self::reportCostTime($url, $startTimeStamp, $result); //上报请求花费时间

        return $result;
    }

    /**
     * 支付结果通用通知
     *
     * @param $msg
     *
     * @return array|bool
     */
    public static function notify(&$msg)
    {
        //获取通知的数据
        //$xml = $GLOBALS['HTTP_RAW_POST_DATA'];
        $xml = file_get_contents("php://input");
        try {
            $result = WxPayDataBase::Init($xml);
        } catch (WxPayException $e) {
            $msg = $e->errorMessage();

            return false;
        }

        return $result;
    }

    /**
     * 返回结果给微信服务器
     *
     * @param $data
     */
    public static function resultXmlToWx($data)
    {
        $notify = new WxPayDataBase();

        $notify->SetReturn_code($data['return_code']);
        $notify->SetReturn_msg($data['return_msg']);
        $xml = $notify->ToXml();

        self::replyNotify($xml);
    }

    /**
     * 直接输出xml
     *
     * @param string $xml
     */
    public static function replyNotify($xml)
    {
        echo $xml;
    }
}
