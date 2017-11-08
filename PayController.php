<?php

namespace app\controllers\api;

use app\components\VpsHelper;
use app\controllers\VpsController;
use app\models\v1\OrderLogModel;
use app\models\v1\OrderModel;
use app\models\v1\ProductModel;
use app\models\v1\UserModel;
use linslin\yii2\curl\Curl;

class PayController extends VpsController {

	const ALI_PAY      = 700;//阿里支付
	const WEI_CHAT_PAY = 800;//微信支付
	const IN_APP_PAY   = 900;//苹果InApp内购支付

	const FORMAT_HTML         = 'HTML';
	const FORMAT_QR           = 'QR';
	const FORMAT_JSON         = 'JSON';
	const FORMAT_URL          = 'URL';
	const FORMAT_ORDER_STRING = 'orderString';

	protected $payConfig;

	public $enableCsrfValidation = false;

	protected $appleInAppBuyVerifyUrlSandbox = 'https://sandbox.itunes.apple.com/verifyReceipt';//测试
	protected $appleInAppBuyVerifyUrl = 'https://buy.itunes.apple.com/verifyReceipt';//线上


	public function __construct($id, \yii\base\Module $module, array $config = []) {
		parent::__construct($id, $module, $config);

		$this->payConfig = require_once \Yii::$app->basePath . '/config/alipayConfig.php';
		require_once \Yii::$app->basePath . '/third/phpqrcode/phpqrcode.php';
		require_once \Yii::$app->basePath . '/third/alipaySdk/AopSdk.php';

	}

	// http://vps.xiongmao.me/api/pay/
	/**
	 * @api               {post} /pay/ 支付地址
	 *
	 * @apiDescription    产品支付地址，根据产品ID，后台进行计算并引导用户到第三方支付界面进行支付【http://vps.xiongmao.me/api/pay/】 <br />
	 *                    *** 此接口返回格式比较特殊 *** <br />
	 *                    成功返回组装好的支付跳转表单 <br />
	 *                    失败返回 code-msg-data 格式的数据，code 代表错误状态 <br />
	 *
	 * @apiVersion        1.0.0
	 *
	 * @apiName           index
	 * @apiGroup          pay
	 *
	 * @apiParam {String} token token值
	 * @apiParam {String} product_id 产品ID
	 * @apiParam {string} format 枚舉值：["HTML", "QR", "JSON", "URL", "orderString"]
	 * @apiParam {String} [size] qr的图片大小
	 * @apiParam {String} [margin] qr的图片补白
	 *
	 * @apiSuccess {int} code 返回代码,<br />
	 * 200: 成功<br />
	 * @apiSuccess {string} msg 不同code代码对应的消息消息
	 * @apiSuccess {Object} data 返回的数据，具体数据格式看正确时的返回值
	 *
	 * @apiSuccessExample Success-Response:
	 *     HTTP/1.1 200 OK
	 *     {
	 *       "code": 500,
	 *       "msg": "发生错误",
	 *       "data": null
	 *     }
	 */
	public function actionIndex() {
		$flag      = true;
		$productId = $this->post('product_id');
		$format    = $this->post('format');
		$size      = intval($this->post('size', 2));
		$margin    = intval($this->post('margin', 1));
		$product   = null;

		// 生成订单信息
		// 将订单 meta 写入数据库
		// 将订单转发给支付平台支付
		// 等待支付平台回掉

		if ( ! $productId ) {
			$flag       = false;
			$this->code = VpsHelper::apiCode('PRODUCT_NOT_EXIST');
			$this->msg  = VpsHelper::apiCode('PRODUCT_NOT_EXIST', false);
		}
		if ( $flag ) {
			$product = ProductModel::find()->where(['id' => $productId])->asArray()->one();
			if ( empty($product) ) {
				$flag       = false;
				$this->code = VpsHelper::apiCode('PRODUCT_NOT_EXIST');
				$this->msg  = VpsHelper::apiCode('PRODUCT_NOT_EXIST', false);
			}
		}
		if ( (int)$this->tokenUserId < 1 ) {
			$flag       = false;
			$this->code = VpsHelper::apiCode('USER_NOT_EXIST');
			$this->msg  = VpsHelper::apiCode('USER_NOT_EXIST', false);
		}
		if ( $flag ) {
			list($tradeNo, $subject, $totalFee, $body) = $this->genPayInfo($product, intval($this->tokenUserId), self::ALI_PAY);

			$aop                        = new \AopClient();
			$aop->appId                 = $this->payConfig['app_id'];
			$aop->rsaPrivateKeyFilePath = \Yii::$app->basePath . '/config/rsa/app_private_key.pem';
			$aop->signType              = $this->payConfig['sign_type'];
			$aop->alipayrsaPublicKey    = $this->payConfig['alipay_public_key'];

			$bizContent = json_encode([
				'out_trade_no' => $tradeNo,
				'product_code' => 'FAST_INSTANT_TRADE_PAY',
				'total_amount' => $totalFee,
				'subject' => $subject,
				'body' => $body,
			]);

			switch ($format) {
				case self::FORMAT_HTML:
					// html form
					$request = new \AlipayTradePagePayRequest();
					$request->setNotifyUrl($this->payConfig['notify_url']);
					$request->setReturnUrl($this->payConfig['return_url']);
					$request->setBizContent($bizContent);
					$response = $aop->pageExecute($request);

					OrderLogModel::saveOrderLog($tradeNo, ['product' => $product, 'submit' => $response]);

					return $response;
				case self::FORMAT_QR:
					// OK queryString
					$request = new \AlipayTradeQueryRequest();
					$request->setNotifyUrl($this->payConfig['notify_url']);
					$request->setReturnUrl($this->payConfig['return_url']);
					$request->setBizContent($bizContent);
					$response = $aop->sdkExecute($request);

					$url = trim($aop->gatewayUrl, '?') . '?' . $response;

					OrderLogModel::saveOrderLog($tradeNo, ['product' => $product, 'submit' => $url]);

					ob_clean();
					\QRcode::png(urldecode($url), false, QR_ECLEVEL_L, $size, $margin, false);
					die();
				case self::FORMAT_URL:
					// OK queryString
					$request = new \AlipayTradeQueryRequest();
					$request->setNotifyUrl($this->payConfig['notify_url']);
					$request->setReturnUrl($this->payConfig['return_url']);
					$request->setBizContent($bizContent);
					$response = $aop->sdkExecute($request);

					$url = trim($aop->gatewayUrl, '?') . '?' . $response;

					OrderLogModel::saveOrderLog($tradeNo, ['product' => $product, 'submit' => $url]);

					return $this->responseApi($url, $this->msg, $this->code);
				case self::FORMAT_JSON:
					// app（IOS）使用的orderString
					$request = new \AlipayTradeAppPayRequest();
					$request->setNotifyUrl($this->payConfig['notify_url']);
					$request->setReturnUrl($this->payConfig['return_url']);
					$request->setBizContent($bizContent);
					$response = $aop->sdkExecute($request);

					// 转换queryString为json
					$resultJson = [];
					$response   = urldecode($response);
					foreach (explode('&', $response) as $index => $item) {
						$kv = explode('=', $item);
						if ( $kv[0] == 'biz_content' ) {
							$kv[1] = json_decode($kv[1], true);
						}
						$resultJson[$kv[0]] = $kv[1];
					}

					OrderLogModel::saveOrderLog($tradeNo, [
						'product' => $product,
						'submit' => json_encode($resultJson)
					]);

					return $this->responseApi($resultJson, $this->msg, $this->code);
				case self::FORMAT_ORDER_STRING:
					// app（IOS）使用的orderString
					$request = new \AlipayTradeAppPayRequest();
					$request->setNotifyUrl($this->payConfig['notify_url']);
					$request->setReturnUrl($this->payConfig['return_url']);
					$request->setBizContent($bizContent);
					$response = $aop->sdkExecute($request);

					OrderLogModel::saveOrderLog($tradeNo, ['product' => $product, 'submit' => $response]);

					return $this->responseApi($response, $this->msg, $this->code);//就是orderString 可以直接给客户端请求，无需再做处理。
				default:
					$this->code = VpsHelper::apiCode('PARAM_ERROR');
					$this->msg  = VpsHelper::apiCode('PARAM_ERROR', false);
					return $this->responseApi($this->data, $this->msg, $this->code);
			}
		}
		return $this->responseApi($this->data, $this->msg, $this->code);
	}

	/**
	 * 生成订单信息，并记录订单日志
	 *
	 * @param $product
	 * @param $userId
	 * @param $payType
	 *
	 * @return array
	 */
	public function genPayInfo($product, $userId, $payType = self::ALI_PAY) {
		$tradeNo  = strval($payType) . date('YmdHis') . rand(100000, 999999) . $userId;
		$subject  = $product['title'] . ($product['discount'] ? ' - ' . $product['discount'] . '折' : '');
		$totalFee = $product['price'] * ($product['discount'] ? $product['discount'] : 100) / 100 ;
		$body     = $product['title'] . ' - ' . $product['description'];

		OrderModel::createOrder($tradeNo, $userId, $product['id'], $totalFee);

		return [$tradeNo, $subject, $totalFee, $body];
	}

	/**
	 * 生成支付表单，用于返回给客户端提交的表单
	 *
	 * @param        $config
	 * @param string $tradeNo  商户订单号，商户网站订单系统中唯一订单号，必填
	 * @param string $subject  订单名称，必填
	 * @param int    $totalFee 付款金额，必填
	 * @param string $body     商品描述，可空
	 *
	 * @return array formData,url
	 */
	public function paySubmit($config, $tradeNo = '', $subject = '', $totalFee = 0, $body = '') {
		/************************************************************/
		//构造要请求的参数数组，无需改动
		//构造要请求的参数数组，无需改动
		$parameter = array(
			"service"           => $config['service'],
			"partner"           => $config['partner'],
			"seller_id"         => $config['partner'],
			"payment_type"      => $config['payment_type'],
			"notify_url"        => $config['notify_url'],
			"return_url"        => $config['return_url'],
			"anti_phishing_key" => $config['anti_phishing_key'],
			"exter_invoke_ip"   => $config['exter_invoke_ip'],
			"out_trade_no"      => $tradeNo,
			"subject"           => $subject,
			"total_fee"         => $totalFee,
			"body"              => $body,
			"_input_charset"    => trim(strtolower($config['input_charset']))
			//其他业务参数根据在线开发文档，添加参数.文档地址:https://doc.open.alipay.com/doc2/detail.htm?spm=a219a.7629140.0.0.kiX33I&treeId=62&articleId=103740&docType=1
			//如"参数名"=>"参数值"
		);
		//建立请求
		$alipaySubmit = new \AlipaySubmit($config);
		list($html_text, $para) = $alipaySubmit->buildRequestForm($parameter, "get", "确认");
		//file_put_contents('2p.log', json_encode([$html_text]) . PHP_EOL . PHP_EOL . PHP_EOL);

		$queryStr = '';
		foreach ($para as $key => $value) {
			$queryStr .= $key . '=' . $value . '&';
		}

		return [$html_text, $alipaySubmit->alipay_gateway_new . $queryStr, $para];
	}


	/**
	 * @api               {post} /pay/notify 支付回调Notify
	 *
	 * @apiDescription    第三方支付完后的回调
	 *
	 * @apiVersion        1.0.0
	 *
	 * @apiName           notify
	 * @apiGroup          pay
	 *
	 * @apiSuccess {string} success|fail 返回字符
	 *
	 * @apiSuccessExample Success-Response:
	 *     HTTP/1.1 200 OK
	 *     success
	 */
	public function actionNotify() {

		$logFile = \Yii::$app->basePath . '/runtime/logs/PAY_NOTIFY_' . date('Ymd') . '.log';
		file_put_contents($logFile, json_encode($_POST, JSON_UNESCAPED_UNICODE) . PHP_EOL . PHP_EOL, FILE_APPEND);

		//简单的请求过滤
		if ( $this->post('out_trade_no') && $this->post('sign') ) {
			OrderLogModel::saveOrderLog(isset($_POST['out_trade_no']) ? $_POST['out_trade_no'] : '', ['notify' => $_POST]);
		}

		// TODO FIXME 严重注意事项，rsaCheckV1的第二个参数，是他妈的[alipay_public_key]，，，你让理解能力不好的怎么办~~~
		$aop                     = new \AopClient();
		$aop->alipayrsaPublicKey = $this->payConfig['alipay_public_key'];
		$verifyResult            = $aop->rsaCheckV1($_POST, $this->payConfig['alipay_public_key'], $this->payConfig['sign_type']);
		//计算得出通知验证结果
		if ( $verifyResult ) {

			//  支付完成的逻辑
			$this->payNotifyLogic($_POST['out_trade_no'], $_POST['trade_no'], self::ALI_PAY, $_POST['trade_status']);

			echo "success";
		} else {
			//验证失败
			echo "fail";
		}

	}

	/**
	 * 具体的回调执行逻辑
	 * 根据内部订单号，增加相应用户的时间
	 *
	 * @param $tradeNo
	 * @param $outTradeNo
	 * @param $payType
	 * @param $status
	 */
	protected function payNotifyLogic($tradeNo, $outTradeNo, $payType = self::ALI_PAY, $status) {
		$statusStr = 'UNPAID';
		if ( $payType == self::ALI_PAY ) {
			if ( $status == 'TRADE_SUCCESS' ) {
				$statusStr = 'SUCCESS';
			}
		}
		if ( $payType == self::IN_APP_PAY ) {
			if ( $status == 0 ) {
				$statusStr = 'SUCCESS';
			}
		}

		$order   = OrderModel::findOne(['trade_no' => $tradeNo]);
		$product = ProductModel::findOne(['id' => intval($order->product_id)]);
		if ( $product ) {
			// 计算时间，并加到用户信息
			$times = $product['gain_time'] + $product['present_time'];
			UserModel::updateUserTime($order['user_id'], $times, false);
			OrderModel::updateOrder($tradeNo, $order['user_id'], $outTradeNo, $statusStr);
		}
	}


	/**
	 * @api               {post} /pay/return 支付回调Return
	 *
	 * @apiDescription    第三方支付完后的回调
	 *
	 * @apiVersion        1.0.0
	 *
	 * @apiName           return
	 * @apiGroup          pay
	 *
	 * @apiSuccess {int} code 返回代码,<br />
	 * 200: 成功<br />
	 * @apiSuccess {string} msg 不同code代码对应的消息消息
	 * @apiSuccess {Object} data 返回的数据
	 *
	 * @apiSuccessExample Success-Response:
	 *     HTTP/1.1 200 OK
	 *     {
	 *       "code": 200,
	 *       "msg": "操作成功",
	 *       "data": null
	 *     }
	 */
	public function actionReturn() {
		$aop                     = new \AopClient();
		$aop->alipayrsaPublicKey = $this->payConfig['alipay_public_key'];
		$verifyResult            = $aop->rsaCheckV1($_GET, $this->payConfig['alipay_public_key'], $this->payConfig['sign_type']);

		if ( ! $verifyResult ) {
			$this->code = VpsHelper::apiCode('PARAM_ERROR');
			$this->msg  = VpsHelper::apiCode('PARAM_ERROR', false);
			return $this->responseApi($this->data, $this->msg, $this->code);
		}
		//TODO 这里需要进行订单以及来源验证，然后在写数据库
		OrderLogModel::saveOrderLog($_GET['out_trade_no'], ['return' => $_GET]);

		$this->msg  = '充值成功！';
		$this->data = $_GET;
		return $this->responseApi($this->data, $this->msg, $this->code);

		//header("location: http://www.xiongmao999.com/Member/Order/?order_id={$return['out_trade_no']}&acts=pay");

	}


	/**
	 * @api               {post} /pay/in-app-purchase-verify iOS应用内购买
	 *
	 * @apiDescription    iOS应用内购买，需要客户端购买玩后，传递[product_id][receipt][transaction_id]，然后服务器进行验证
	 *
	 * @apiVersion        1.0.0
	 *
	 * @apiName           in-app-purchase-verify
	 * @apiGroup          pay
	 *
	 * @apiParam {String} product_id 后台的产品id，在产品接口可以获得
	 * @apiParam {String} receipt 内购成功后接受到的 receipt_data
	 * @apiParam {String} [transaction_id] 交易ID，使用内购的receipt 去苹果服务器验证后得到的值【in_app最新记录的transaction_id】
	 * @apiParam {String} [environment] 环境 值是["AppStore", "Sandbox"]，取值为默认"Sandbox",表示沙盒环境，"AppStore"表示正式环境
	 *
	 * @apiSuccess {int} code 返回代码,<br />
	 * 200: 验证成功，时间已经加到用户身上。<br />
	 * 10015: 产品不存在<br />
	 * 10017: 订单验证错误<br />
	 * 10018: 交易号不存在<br />
	 * 10019: 交易冲突（交易已将完成，不能重复提交）<br />
	 * @apiSuccess {string} msg 不同code代码对应的消息消息
	 * @apiSuccess {Object} data 返回的数据
	 *
	 * @apiSuccessExample Success-Response:
	 *     HTTP/1.1 200 OK
	 *     {
	 *       "code": 200,
	 *       "msg": "验证成功",
	 *       "data": null
	 *     }
	 */
	public function actionInAppPurchaseVerify() {
		$flag      = true;
		$productId = $this->post('product_id');
		$receipt   = $this->post('receipt');
		$transId   = $this->post('transaction_id');
		$environment = $this->post('environment');

		$status  = null;
		$product = null;
		$order   = null;

		// https://developer.apple.com/library/content/releasenotes/General/ValidateAppStoreReceipt/Chapters/ValidateRemotely.html
		// http://www.cnblogs.com/zhaoqingqing/p/4597794.html

		switch (strtoupper($environment)) {
			case 'APPSTORE':
				break;
			case 'SANDBOX':
				$this->appleInAppBuyVerifyUrl = $this->appleInAppBuyVerifyUrlSandbox;
				break;
			default:
				$this->appleInAppBuyVerifyUrl = $this->appleInAppBuyVerifyUrlSandbox;
				break;
		}

		if ( ! $productId ) {
			$flag       = false;
			$this->code = VpsHelper::apiCode('PRODUCT_NOT_EXIST');
			$this->msg  = VpsHelper::apiCode('PRODUCT_NOT_EXIST', false);
		}
		if ( $flag ) {
			$product = ProductModel::find()->where(['id' => $productId])->asArray()->one();
			if ( empty($product) ) {
				$flag       = false;
				$this->code = VpsHelper::apiCode('PRODUCT_NOT_EXIST');
				$this->msg  = VpsHelper::apiCode('PRODUCT_NOT_EXIST', false);
			}
		}
		if ( $flag ) {
			list($status, $order) = $this->inAppPurchaseReceiptVerify($receipt, $transId, $productId);
			if ( $status != 0 ) {
				$flag       = false;
				$this->code = VpsHelper::apiCode('ORDER_VERIFY_ERROR');
				$this->msg  = VpsHelper::apiCode('ORDER_VERIFY_ERROR', false) . ', status:' . $status;
			}
		}
		if ( $flag ) {
			$find = OrderModel::find()->where(['out_trade_no' => $order['transaction_id'], 'status' => 'SUCCESS'])->one();
			if ( ! empty($find) && substr($find['trade_no'], 0, strlen(self::IN_APP_PAY)) == self::IN_APP_PAY ) {
				$flag       = false;
				$this->code = VpsHelper::apiCode('TRANSACTION_CONFLICT');
				$this->msg  = VpsHelper::apiCode('TRANSACTION_CONFLICT', false) . '(已完成)';
				$this->data = [
					'trade_no' => $find['trade_no'],
				];
			}
		}
		if ( $flag ) {
			//OK
			list($tradeNo, $subject, $totalFee, $body) = $this->genPayInfo($product, intval($this->tokenUserId), self::IN_APP_PAY);
			$submit = ['product_id' => $productId, 'transaction_id' => $transId, 'receipt' => $receipt];
			OrderLogModel::saveOrderLog($tradeNo, ['product' => $product, 'submit' => $submit, 'notify' => $order]);

			$this->payNotifyLogic($tradeNo, $transId, self::IN_APP_PAY, $status);

			$this->msg = '操作成功！';
		}
		return $this->responseApi($this->data, $this->msg, $this->code);
	}

	/**
	 * 验证 receipt 并返回购买的 product_id
	 *
	 * @param string $receipt
	 * @param string $transactionId
	 * @param string $productId
	 *
	 * @return mixed
	 */
	protected function inAppPurchaseReceiptVerify($receipt = '', $transactionId = '', $productId = '') {
		$status = -1;
		$order  = [];
		$curl   = new Curl();
		$curl->setOption(CURLOPT_SSL_VERIFYHOST, false);
		$curl->setOption(CURLOPT_SSL_VERIFYPEER, false);
		$curl->setRequestBody(json_encode([
			'receipt-data'             => $receipt,
			'password'                 => 'Yios@20.30',
			'exclude-old-transactions' => true,
		]));
		$response = $curl->post($this->appleInAppBuyVerifyUrl);
		$response = json_decode($response, true);
		if ( isset($response['status']) ) {
			if ( $response['status'] == 0 ) {
				$orderList = $response['receipt']['in_app'];
				foreach ($orderList as $tmpOrder) {
					if ( $tmpOrder['transaction_id'] == $transactionId ) {
						$order  = $tmpOrder;
						$status = $response['status'];
						break;
					}
				}
				if ( $response['status'] != 0 ) {
					\Yii::warning([
						'message' => '交易不存在！',
						'receipt' => $receipt,
						'productId' => $productId,
						'transactionId' => $transactionId,
					], 'IN_APP_RECEIPT_VERIFY_ERROR');
				}
			}
		}

		$logFile = \Yii::$app->basePath . '/runtime/logs/IN_APP_RECEIPT_VERIFY_' . date('Ymd') . '.log';
		file_put_contents($logFile, json_encode($response, JSON_UNESCAPED_UNICODE) . PHP_EOL . PHP_EOL, FILE_APPEND);

		return array($status, $order);
	}


}
