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
	const REDIRECT_URI		 = 'redirect_uri';
	const DISPLAY				 = 'display';
	const ACCESS_TOKEN		 = 'access_token';
	const REFRESH_TOKEN		 = 'refresh_token';
	const EXPIRES_IN			 = 'expires_in';
	const PERMISSION			 = 'permission';
	const SESSION_KEY_PREFIX	 = 'mixi';

	protected $_config;
	protected $_nowGetTokenFlag	 = false;
	protected $_user			 = null;

	public function __construct(array $config) {
		$this->_config = array_merge(array(
			self::CONSUMER_KEY		 => '',
			self::CONSUMER_SECRET	 => '',
			self::SCOPE				 => 'r_profile',
			self::REDIRECT_URI		 => '',
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
				self::REDIRECT_URI	 => $this->_config[self::REDIRECT_URI],
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

		list($head, $body) = $this->httpRequest($method, $url, $content, $params);


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

	public function ends_with($search, $subject) {
		$l = strlen($search);
		return ($l <= strlen($subject) && $search == substr($subject, -1 * $l));
	}

	protected function httpRequest($method, $url, $content = null, array $params = array()) {
		if ($method == 'GET') {
			return $this->httpGet($url, $content, $params);
		}
		else {
			return $this->httpPost($url, $content, $params);
		}
	}

	/**
	 * httpGet
	 * 
	 * @param string $url
	 * @param array $params
	 * @param mixed $content
	 * @return array header, body
	 */
	protected function httpGet($url, $content = null, array $params = array()) {
		if ($content) {
			if (is_string($content)) {
				$get = $url . '?' . $content;
			}
			else {
				$get = $url . '?' . http_build_query($content);
			}
		}
		else {
			$get = $url;
		}

		$context = stream_context_create(array('http' => $params));
		$body	 = file_get_contents($get, false, $context);
		return array($http_response_header, $body);
	}

	/**
	 * httpPost
	 * 
	 * $contentには、http_build_query関数の結果か、makeMultipartContentメソッドで解釈できるarrayを渡すこと
	 * 
	 * array( 'name' => 'content' )
	 * array( 'name' => '@filepath' )
	 * array( 'name' => array( 'file' => 'filepath' [, 'name' => 'filename'][, 'type' => 'Content-Type value'][, 'encoding' => 'Content-Transfer-Encoding value'] ) )
	 * array( 'name' => array( 'content' => 'content' [, 'name' => 'contentname'][, 'type' => 'Content-Type value'][, 'encoding' => 'Content-Transfer-Encoding value'] ) )
	 * 
	 * @method post
	 * @param string $url
	 * @param mixed $content	string(http query) or array(multi part)
	 * @param array $params
	 * @return array header, body
	 */
	protected function httpPost($url, $content = null, array $params = array()) {
		$params['method']		 = 'POST';
		$params['user_agent']	 = 'atomita/mixi#httpPost';

		// content
		if (is_array($content)) {
			$boundary	 = '---------------------' . substr(md5(rand(0, 32000)), 0, 10);
			$content	 = $this->makeMultipartContent($content, $boundary);
		}
		if ($content) {
			$params['content'] = $content;
		}

		// header
		if (isset($params['header'])) {
			if (is_string($params['header'])) {
				$params['header'] = explode("\r\n", $params['header']);
			}
			$headers_kv = array();
			foreach ($params['header'] as $key => $value) {
				if (is_int($key)) {
					list($k, $v) = explode(':', $value, 2);
					if (trim($k) && trim($v)) {
						$headers_kv[trim($k)] = trim($v);
					}
					else {
						$headers_kv[] = $value;
					}
				}
				else {
					$headers_kv[$key] = $value;
				}
			}
			if (isset($boundary)) {
				unset($headers_kv['Content-Type']);
				$headers = array("Content-Type: multipart/form-data; boundary={$boundary}");
			}
			foreach ($headers_kv as $key => $value) {
				if (is_int($key)) {
					$headers[] = $value;
				}
				else {
					$headers[] = $key . ': ' . $value;
				}
			}
			$params['header'] = implode("\r\n", $headers);
		}
		else {
			$params['header'] = isset($boundary) ? "Content-Type: multipart/form-data; boundary={$boundary}" : '';
		}

		$context = stream_context_create(array('http' => $params));
		$body	 = file_get_contents($url, false, $context);
		return array($http_response_header, $body);
	}

	/**
	 * makeMultipartContent
	 * 
	 * @param array $contents
	 * @param string $boundary
	 * @return string 
	 */
	protected function makeMultipartContent(array $contents, $boundary) {
		$rows = array('--' . $boundary);

		foreach ($contents as $key => $value) {
			$rows[] = 'Content-Disposition: form-data; name="' . $key . '"';

			if (is_array($value) || (0 === strpos($value, '@') && file_exists($file = substr($value, 1)))) {

				$type		 = $encoding	 = $content	 = $name		 = null;
				if (is_array($value)) {
					if (isset($value['file'])) {
						$file = $value['file'];
					}
					else {
						$file = null;
					}
					if (isset($value['name'])) {
						$name = $value['name'];
					}
					if (isset($value['content'])) {
						$content = $value['content'];
					}
					if (isset($value['type'])) {
						$type = $value['type'];
					}
					if (isset($value['encoding'])) {
						$encoding = $value['encoding'];
					}
				}
				if (!$type && $file) {
					$type = $this->getByFileName($file);
				}
				if (!$encoding && $type) {
					if (!$this->starts_with('text', $type) && !$this->ends_with('xml', $type) && !$this->ends_with('json', $type) && !$this->ends_with('script', $type)) {
						$encoding = 'binary';
					}
				}
				if (!$name && $file) {
					$name = basename($file);
				}

				if ($name) {
					$rows[count($rows) - 1] .= '; filename=' . $name;
				}
				if ($type) {
					$rows[] = 'Content-Type: ' . $type;
				}
				if ($encoding) {
					$rows[] = 'Content-Transfer-Encoding: ' . $encoding;
				}

				if ($file) {
					$value = file_get_contents($file);
				}
				else {
					$value = $content;
				}
			}
			$rows[]	 = '';
			$rows[]	 = $value;
			$rows[]	 = '--' . $boundary;
		}
		$rows[] = '';
		return implode("\r\n", $rows);
	}

	/**
	 * getByFileName
	 *
	 * @param {string} $file_name file name
	 * @return {string} MIME type
	 */
	protected function getByFileName($file_name) {
		$paths	 = explode(DIRECTORY_SEPARATOR, $file_name);
		$file	 = array_pop($paths);
		if ($file && ($pos	 = strrpos('.', $file)) !== false) {
			$ext = substr($file, $pos + 1);
		}
		else {
			$ext = '';
		}
		return $this->getByExtension($ext);
	}

	/**
	 * getByExtension
	 *
	 * @param {string} $ext Extension
	 * @return {string} MIME type
	 */
	protected function getByExtension($ext) {
		static $_ext2mime = array(
			'ez'		 => 'application/andrew-inset',
			'atom'		 => 'application/atom+xml',
			'oda'		 => 'application/oda',
			'ogg'		 => 'application/ogg',
			'pdf'		 => 'application/pdf',
			'ai'		 => 'application/postscript',
			'eps'		 => 'application/postscript',
			'ps'		 => 'application/postscript',
			'rdf'		 => 'application/rdf+xml',
			'rtf'		 => 'application/rtf',
			'smi'		 => 'application/smil',
			'smil'		 => 'application/smil',
			'gram'		 => 'application/srgs',
			'grxml'		 => 'application/srgs+xml',
			'apk'		 => 'application/vnd.android.package-archive',
			'kml'		 => 'application/vnd.google-earth.kml+xml',
			'kmz'		 => 'application/vnd.google-earth.kmz',
			'xul'		 => 'application/vnd.mozilla.xul+xml',
			'xls'		 => 'application/vnd.ms-excel',
			'ppt'		 => 'application/vnd.ms-powerpoint',
			'wbxml'		 => 'application/vnd.wap.wbxml',
			'wmlc'		 => 'application/vnd.wap.wmlc',
			'wmlsc'		 => 'application/vnd.wap.wmlscriptc',
			'vxml'		 => 'application/voicexml+xml',
			'bcpio'		 => 'application/x-bcpio',
			'vcd'		 => 'application/x-cdlink',
			'pgn'		 => 'application/x-chess-pgn',
			'cpio'		 => 'application/x-cpio',
			'csh'		 => 'application/x-csh',
			'dcr'		 => 'application/x-director',
			'dir'		 => 'application/x-director',
			'dxr'		 => 'application/x-director',
			'dvi'		 => 'application/x-dvi',
			'ebk'		 => 'application/x-expandedbook',
			'spl'		 => 'application/x-futuresplash',
			'gtar'		 => 'application/x-gtar',
			'hdf'		 => 'application/x-hdf',
			'php'		 => 'application/x-httpd-php',
			'jam'		 => 'application/x-jam',
			'js'		 => 'text/javascript',
			'kjx'		 => 'application/x-kj',
			'skp'		 => 'application/x-koan',
			'skd'		 => 'application/x-koan',
			'skt'		 => 'application/x-koan',
			'skm'		 => 'application/x-koan',
			'latex'		 => 'application/x-latex',
			'amc'		 => 'application/x-mpeg',
			'nc'		 => 'application/x-netcdf',
			'cdf'		 => 'application/x-netcdf',
			'sh'		 => 'application/x-sh',
			'shar'		 => 'application/x-shar',
			'swf'		 => 'application/x-shockwave-flash',
			'mmf'		 => 'application/x-smaf',
			'sit'		 => 'application/x-stuffit',
			'sv4cpio'	 => 'application/x-sv4cpio',
			'sv4crc'	 => 'application/x-sv4crc',
			'tar'		 => 'application/x-tar',
			'tcl'		 => 'application/x-tcl',
			'tex'		 => 'application/x-tex',
			'texinfo'	 => 'application/x-texinfo',
			'texi'		 => 'application/x-texinfo',
			't'			 => 'application/x-troff',
			'tr'		 => 'application/x-troff',
			'roff'		 => 'application/x-troff',
			'man'		 => 'application/x-troff-man',
			'me'		 => 'application/x-troff-me',
			'ms'		 => 'application/x-troff-ms',
			'ustar'		 => 'application/x-ustar',
			'src'		 => 'application/x-wais-source',
			'zac'		 => 'application/x-zaurus-zac',
			'xhtml'		 => 'application/xhtml+xml',
			'xht'		 => 'application/xhtml+xml',
			'dtd'		 => 'application/xml-dtd',
			'xslt'		 => 'application/xslt+xml',
			'zip'		 => 'application/zip',
			'au'		 => 'audio/basic',
			'snd'		 => 'audio/basic',
			'mid'		 => 'audio/midi',
			'midi'		 => 'audio/midi',
			'kar'		 => 'audio/midi',
			'mpga'		 => 'audio/mpeg',
			'mp2'		 => 'audio/mpeg',
			'mp3'		 => 'audio/mpeg',
			'qcp'		 => 'audio/vnd.qcelp',
			'aif'		 => 'audio/x-aiff',
			'aiff'		 => 'audio/x-aiff',
			'aifc'		 => 'audio/x-aiff',
			'm3u'		 => 'audio/x-mpegurl',
			'wax'		 => 'audio/x-ms-wax',
			'wma'		 => 'audio/x-ms-wma',
			'ram'		 => 'audio/x-pn-realaudio',
			'rm'		 => 'audio/x-pn-realaudio',
			'rpm'		 => 'audio/x-pn-realaudio-plugin',
			'ra'		 => 'audio/x-realaudio',
			'vqf'		 => 'audio/x-twinvq',
			'vql'		 => 'audio/x-twinvq',
			'vqe'		 => 'audio/x-twinvq-plugin',
			'wav'		 => 'audio/x-wav',
			'igs'		 => 'model/iges',
			'iges'		 => 'model/iges',
			'msh'		 => 'model/mesh',
			'mesh'		 => 'model/mesh',
			'silo'		 => 'model/mesh',
			'wrl'		 => 'model/vrml',
			'vrml'		 => 'model/vrml',
			'ics'		 => 'text/calendar',
			'ifb'		 => 'text/calendar',
			'css'		 => 'text/css',
			'html'		 => 'text/html',
			'htm'		 => 'text/html',
			'asc'		 => 'text/plain',
			'txt'		 => 'text/plain',
			'rtx'		 => 'text/richtext',
			'sgml'		 => 'text/sgml',
			'sgm'		 => 'text/sgml',
			'tsv'		 => 'text/tab-separated-values',
			'rt'		 => 'text/vnd.rn-realtext',
			'jad'		 => 'text/vnd.sun.j2me.app-descriptor',
			'wml'		 => 'text/vnd.wap.wml',
			'wmls'		 => 'text/vnd.wap.wmlscript',
			'hdml'		 => 'text/x-hdml;charset=Shift_JIS',
			'etx'		 => 'text/x-setext',
			'xml'		 => 'text/xml',
			'xsl'		 => 'text/xml',
			'mpeg'		 => 'video/mpeg',
			'mpg'		 => 'video/mpeg',
			'mpe'		 => 'video/mpeg',
			'qt'		 => 'video/quicktime',
			'mov'		 => 'video/quicktime',
			'mxu'		 => 'video/vnd.mpegurl',
			'm4u'		 => 'video/vnd.mpegurl',
			'rv'		 => 'video/vnd.rn-realvideo',
			'mng'		 => 'video/x-mng',
			'asf'		 => 'video/x-ms-asf',
			'asx'		 => 'video/x-ms-asf',
			'avi'		 => 'video/x-msvideo',
			'movie'		 => 'video/x-sgi-movie',
			'ice'		 => 'x-conference/x-cooltalk',
			'd96'		 => 'x-world/x-d96',
			'mus'		 => 'x-world/x-d96',
			'download'	 => 'application/force-download',
			'json'		 => 'application/json',
			'jpg'		 => 'image/jpeg',
			'jpeg'		 => 'image/jpeg',
			'png'		 => 'image/png',
			'gif'		 => 'image/gif',
		);
		if (isset($_ext2mime[$ext])) {
			return $_ext2mime[$ext];
		}
		else {
			return '';
		}
	}

}

/**
 * MixiException
 * 
 */
class MixiException extends \Exception {

	private $_api_err = '';

	public function __construct($mixi_api_err, $message = null, $code = null, $previous = null) {
		parent::__construct($message, $code, $previous);

		$this->_api_err = $mixi_api_err;
	}

	public function getApiError() {
		return $this->_api_err;
	}

}
