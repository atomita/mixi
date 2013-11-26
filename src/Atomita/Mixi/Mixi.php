<?php

namespace Atomita\Mixi;

/**
 * mixi Graph API access tools
 * 
 * @see http://developer.mixi.co.jp/connect/mixi_graph_api/
 * 
 * @author atomita
 * @license MIT
 * @version 0.0.1
 */
class Mixi {

	const CONSUMER_KEY		 = 'consumer_key';
	const CONSUMER_SECRET		 = 'consumer_secret';
	const SCOPE				 = 'scope';
	const REDIRECT_URL		 = 'redirect_uri';
	const DISPLAY				 = 'display';
	const ACCESS_TOKEN		 = 'access_token';
	const REFRESH_TOKEN		 = 'refresh_token';
	const EXPIRES_IN			 = 'expires_in';
	const PERMISSION			 = 'permission';
	const SESSION_KEY_PREFIX	 = 'mixi';

	protected $_config;
	protected $_nowGetTokenFlag	 = false;
	protected $_user			 = null;

	public function __construct($config) {
		$this->$config = array_merge(array(
			self::CONSUMER_KEY		 => '',
			self::CONSUMER_SECRET	 => '',
			self::SCOPE				 => 'r_profile',
			self::REDIRECT_URL		 => '',
			self::DISPLAY			 => 'touch')
				, $config);

		if (!session_id()) {
			session_start();
		}
		if (isset($_GET['code'])) {
			// get access token
			$data = array(
				'grant_type'		 => 'authorization_code',
				'client_id'			 => $this->_config[self::CONSUMER_KEY],
				'client_secret'		 => $this->_config[self::CONSUMER_SECRET],
				'code'				 => $_GET['code'],
				self::REDIRECT_URL	 => $this->_config[self::REDIRECT_URL],
			);
			$this->_getToken($data);
		}
	}

	/**
	 * getToken
	 * 
	 * @param array $params
	 * @return boolean 
	 */
	protected function _getToken(array $params) {
		$data		 = http_build_query($params, '', '&');
		$context	 = array(
			'http' => array(
				'method'	 => 'POST',
				'header'	 => implode("\r\n", array(
					'Content-Type: application/x-www-form-urlencoded',
					'Content-Length: ' . strlen($data),
				)),
				'content'	 => $data,
			)
		);
		//アクセストークン、リフレッシュトークンを取得
		$json		 = file_get_contents('https://secure.mixi-platform.com/2/token', false, stream_context_create($context));
		$response	 = json_decode($json, true);

		if ($response && isset($response[self::ACCESS_TOKEN])) {
			$this->setOauthInfo(
					$response[self::ACCESS_TOKEN], $response[self::REFRESH_TOKEN]
					, time() + $response[self::EXPIRES_IN] - 60
					, isset($response[self::SCOPE]) ? $response[self::SCOPE] : null );
			$ret = true;
		}
		else {
			$ret = false;
		}
		$this->_nowGetTokenFlag = $ret;
		return $ret;
	}

	/**
	 * setOauthInfo
	 * 
	 * @param string $access_token
	 * @param string $refresh_token
	 * @param int $expires_in 
	 * @param string $permission
	 */
	public function setOauthInfo($access_token, $refresh_token, $expires_in, $permission = null) {
		$session = array(
			self::ACCESS_TOKEN	 => $access_token,
			self::REFRESH_TOKEN	 => $refresh_token,
			self::EXPIRES_IN	 => $expires_in,
		);
		if ($permission) {
			$session[self::PERMISSION] = $permission;
		}
		$this->_setSessionData($session);
	}

	/**
	 * _setSessionData
	 * 
	 * $data is key-value-store
	 * key is 'code', 'request_token', 'request_token_secret', 'access_token', 'access_secret'
	 * 
	 * @param array $data 
	 */
	protected function _setSessionData(array $data) {
		$session_key = $this->_getSessionKey();
		if (!isset($_SESSION[$session_key]) || !is_array($_SESSION[$session_key])) {
			$_SESSION[$session_key] = array(
				self::ACCESS_TOKEN	 => null,
				self::REFRESH_TOKEN	 => null,
				self::PERMISSION	 => '',
				self::EXPIRES_IN	 => 0,
			);
		}
		$_SESSION[$session_key] = array_merge($_SESSION[$session_key], $data);
	}

	protected function _getSessionKey() {
		return implode('_', array(self::SESSION_KEY_PREFIX, $this->_config[self::CONSUMER_KEY]));
	}

	/**
	 * getOauthInfo
	 * 
	 * @return array access_token, refresh_token, expires_in and permission
	 */
	public function getOauthInfo() {
		$session = $this->_getSessionData();
		return array($session[self::ACCESS_TOKEN], $session[self::REFRESH_TOKEN], $session[self::EXPIRES_IN], $session[self::PERMISSION]);
	}

	/**
	 * isAuthenticated
	 * 
	 * 認証済みか？
	 * 
	 * @method isAuthenticated
	 * @return boolean 
	 */
	public function isAuthenticated() {
		$session = $this->_getSessionData();
		return $session[self::ACCESS_TOKEN] && $session[self::REFRESH_TOKEN] && $session[self::EXPIRES_IN] && (time() < $session[self::EXPIRES_IN]);
	}

	/**
	 * isApproved
	 * 
	 * 権限があるか？
	 *
	 * @param string $scopes
	 * @return boolean
	 */
	public function isApproved($scopes = null) {
		if (!$this->isAuthenticated()) {
			return false;
		}

		$scopes = $scopes ? $scopes : ($this->_config[self::SCOPE] ? $this->_config[self::SCOPE] : '');

		$permission = $this->_getSessionData(self::PERMISSION);
		if (!$permission or !trim($scopes)) {
			return false;
		}

		$permissions = explode(' ', $permission);
		foreach (explode(' ', $scopes) as $permission) {
			if ($permission and !in_array($permission, $permissions)) {
				return false;
			}
		}
		return true;
	}

	/**
	 * getLoginUrl
	 * 
	 * echo '<script type="text/javascript">top.location.href = "' . $mixi->getLoginUrl() . '";</script>';
	 * 
	 * @method getLoginUrl
	 * @param array $params	key is 'state'
	 * @return type 
	 */
	public function getLoginUrl(array $params = array()) {
		$app_params = array_merge(array(
			'client_id'		 => $this->_config[self::CONSUMER_KEY],
			'response_type'	 => 'code',
			'display'		 => $this->_config[self::DISPLAY],
			'scope'			 => $this->_config[self::SCOPE],
				), $params);
		return 'https://mixi.jp/connect_authorize.pl?' . http_build_query($app_params, '', '&');
	}

	/**
	 * refreshToken
	 * 
	 * @method refreshToken
	 * @param boolean $is_force 
	 * @return boolean 
	 */
	public function refreshToken($is_force = false) {
		if ($this->_nowGetTokenFlag !== null && !$is_force) {
			return $this->_nowGetTokenFlag;
		}

		$refresh_token = $this->_getSessionData(self::REFRESH_TOKEN);
		if (!$refresh_token) {
			return false;
		}

		//リクエストの設定
		$data = array(
			'grant_type'		 => self::REFRESH_TOKEN,
			'client_id'			 => $this->_config[self::CONSUMER_KEY],
			'client_secret'		 => $this->_config[self::CONSUMER_SECRET],
			self::REFRESH_TOKEN	 => $refresh_token,
		);
		return self::_getToken($data);
	}

	/**
	 * _getSessionData
	 * 
	 * @method _getSessionData
	 * @return mixed 
	 */
	protected function _getSessionData($key = null) {
		$session_key = $this->_getSessionKey();
		if (!isset($_SESSION[$session_key])) {
			$this->_setSessionData(array());
		}
		$session = $_SESSION[$session_key];
		if ($key) {
			return isset($session[$key]) ? $session[$key] : '';
		}
		return $session;
	}

	/**
	 * getUserData
	 * 
	 * ユーザデータの取得 
	 * 
	 * @method getUserData
	 * @param $is_force
	 * @return array
	 * @throws MixiException 
	 */
	public function getUserData($is_force = false) {
		if (!$this->_user || $is_force) {
			$this->_user = $this->api('/2/people/@me/@self/', 'GET', array('oauth_token' => self::_getSessionData(self::ACCESS_TOKEN)));
		}
		return $this->_user;
	}

	/**
	 * getUserId
	 * 
	 * @method getUserId
	 * @return string 
	 */
	public function getUserId() {
		$userinfo = $this->getUserData();
		return $userinfo['entry']['id'];
	}

	/**
	 * getUserName
	 * 
	 * @method getUserName
	 * @return string 
	 */
	public function getUserName() {
		$userinfo = $this->getUserData();
		return $userinfo['entry']['displayName'];
	}

	/**
	 * getUserThumbnailUrl
	 * 
	 * @method getUserThumbnailUrl
	 * @return string 
	 */
	public function getUserThumbnailUrl() {
		$userinfo = $this->getUserData();
		return $userinfo['entry']['thumbnailUrl'];
	}

	/**
	 * sendUserVoice
	 * 
	 * mixiボイスに投稿
	 * 
	 * @method sendUserVoice
	 * @param array $params key is 'status', 'photo'(@filepath)
	 * @return boolean
	 * @throws MixiException 
	 */
	public function sendUserVoice(array $params = array()) {
		return $this->api('/2/voice/statuses/update', 'POST', $params, true, isset($params['photo']));
	}

	/**
	 * api
	 * 
	 * @method api
	 * @param string $api
	 * @param string $method
	 * @param array $contents
	 * @param boolean $is_refresh_try
	 * @param boolean $file_upload
	 * @return mixed
	 * @throws MixiException 
	 */
	public function api($api, $method = 'GET', array $contents = array(), $is_refresh_try = true, $file_upload = false) {

		if ($this->starts_with($api, 'http')) {
			$url = $api;
		}
		else {
			$url = 'https://api.mixi-platform.com/' . ltrim($api, '/');
		}

		$access_token = $this->_getSessionData(self::ACCESS_TOKEN);

		$contents['format'] = 'json';
		if ($file_upload && $contents) {
			$content = $contents;
		}
		else {
			$content = http_build_query($contents, '', '&');
		}

		if ('GET' == $method) {
			if (!$content) {
				$content = array('oauth_token' => $access_token);
			}
			elseif (is_array($content)) {
				$content['oauth_token'] = $access_token;
			}
			elseif (is_string($content)) {
				$content .= '&oauth_token=' . $access_token;
			}
			$params = array();
		}
		else {
			$params = array(
				'header' => array(
					'Content-Type'	 => 'application/x-www-form-urlencoded',
					'Authorization'	 => 'OAuth ' . $access_token,
				)
			);
		}

		list($head, $body) = AttoRequestHelper::request($method, $url, $content, $params);


		$error = $this->_isErrorResponse($body, $head);
		if ($error) {
			if ($is_refresh_try && $error === 'expired_token' && $this->refreshToken()) {
				//レスポンスヘッダーの中に、アクセストークン切れエラーが入っていた場合、リフレッシュトークン出来たら再実行
				return $this->api($url, $method, $contents, false);
			}
			throw new MixiException('api access error');
		}
		else {
			if ($body) {
				return json_decode($body, true);
			}
			else {
				return true;
			}
		}
	}

	/**
	 * _parseHeader
	 * 
	 * レスポンスヘッダーの整形
	 * 
	 * @method _parseHeader
	 * @param array $headers
	 * @return array 
	 */
	protected function _parseHeader($headers) {
		$statusLine	 = array_shift($headers);
		$result		 = array();
		list(, $result['Status'], ) = explode(' ', $statusLine);
		foreach ($headers as $header) {
			list($key, $value) = explode(': ', $header);
			$result[$key] = $value;
		}
		return $result;
	}

	/**
	 * _isErrorResponse
	 * 
	 * @method _isErrorResponse
	 * @param string $body
	 * @param array $header
	 * @return boolean or string
	 * 
	 * invalid_request		不正なリクエスト内容
	 * invalid_token		不正なアクセストークン
	 * expired_token		アクセストークンの有効期限切れ
	 * insufficient_scope	アクセスに必要なスコープが認可されていない
	 * invalid_grant		リフレッシュトークンの期限切れ
	 */
	protected function _isErrorResponse($body, $header) {
		$headers = $this->_parseHeader($header);
		$status	 = intval($headers['Status']);
		if (200 <= $status && $status < 300) {
			return false;
		}

		if (array_key_exists('WWW-Authenticate', $headers)) {
			$matchs = null;
			if (preg_match('/error=["\'](.*?)["\']/', $headers['WWW-Authenticate'], $matchs)) {
				return trim($matchs[1]);
			}
		}

		$json = json_decode($body, true);
		if (isset($json['error'])) {
			return trim($json['error']);
		}

		return 'undefined error';
	}

	public function starts_with($search, $subject) {
		return (0 === strpos($subject, $search));
	}

}

/**
 * MixiException
 * 
 */
class MixiException extends Exception {

	private $_api_err = '';

	public function __construct($mixi_api_err, $message = null, $code = null, $previous = null) {
		parent::__construct($message, $code, $previous);

		$this->_api_err = $mixi_api_err;
	}

	public function getApiError() {
		return $this->_api_err;
	}

}
