<?php
/**
 * Twitter-API-PHP : Simple PHP wrapper for the v1.1 API.
 *
 * PHP version 5.3.10
 *
 * @category Awesomeness
 * @package Twitter-API-PHP
 * @author James Mallison <me@j7mbo.co.uk>
 * @license MIT License
 * @version 1.0.4
 * @link http://github.com/j7mbo/twitter-api-php
 */

namespace Drupal\tweets;

class TwitterAPIExchange {
  /**
  * oauthAccessToken.
  *
  * @var string
  */
  private $oauthAccessToken;
  /**
  * oauthAccessTokenSecret.
  *
  * @var string
  */
  private $oauthAccessTokenSecret;
  /**
  * consumerKey.
  *
  * @var string
  */
  private $consumerKey;
  /**
  * consumerSecret.
  *
  * @var string
  */
  private $consumerSecret;
  /**
  * postfields.
  *
  * @var array
  */
  private $postfields;
  /**
  * getfield.
  *
  * @var string
  */
  private $getfield;
  /**
  * oauth.
  *
  * @var mixed
  */
  protected $oauth;
  /**
  * Transliteration service.
  *
  * @var string
  */
  public $url;
  /**
  * Transliteration service.
  *
  * @var string
  */
  public $requestMethod;

  /**
  * Create the API access object. Requires an array of settings::.
  * oauth access token, oauth access token secret, consumer key, consumer secret.
  * These are all available by creating your own application on dev.twitter.com.
  * Requires the cURL library.
  *
  * @throws \Exception
  *
  * @param array $settings
  */
  public function __construct(array $settings) {
    if (!in_array('curl', get_loaded_extensions())) {
      $msg = 'You need to install cURL, see: http://curl.haxx.se/docs/install.html';
      \Drupal::logger('tweets')->notice($msg);
    }

    if (!isset($settings['oauth_access_token'])
    || !isset($settings['oauth_access_token_secret'])
    || !isset($settings['consumer_key'])
    || !isset($settings['consumer_secret'])
  ) {
    $msg = 'Make sure you are passing in the correct parameters';
    \Drupal::logger('tweets')->notice($msg);
  }
  $this->oauth_access_token = $settings['oauth_access_token'];
  $this->oauth_access_token_secret = $settings['oauth_access_token_secret'];
  $this->consumer_key = $settings['consumer_key'];
  $this->consumer_secret = $settings['consumer_secret'];
}

/**
* Set postfields array, example: array('screen_name' => 'J7mbo')
*
* @param array
*
* @throws \Exception
*
* @return TwitterAPIExchange Instance of self for method chaining
*/
public function setPostfields(array $array) {
  if (!is_null($this->getGetfield())) {
    $msg = 'You can only choose get OR post fields.';
    \Drupal::logger('my_module')->notice($msg);
  }

  if (isset($array['status']) && substr($array['status'], 0, 1) === '@') {
    $array['status'] = sprintf("\0%s", $array['status']);
  }
  foreach ($array as $key => &$value) {
    if (is_bool($value)) {
      $value = ($value === TRUE) ? 'true' : 'false';
    }
  }

  $this->postfields = $array;

  // Rebuild oAuth.
  if (isset($this->oauth['oauth_signature'])) {
    $this->buildOauth($this->url, $this->requestMethod);
  }
  return $this;
}

/**
* Set getfield string, example: '?screen_name=J7mbo'.
*
* @param string
*
* @throws \Exception
*
* @return \TwitterAPIExchange
*/
public function setGetfield($string) {
  if (!is_null($this->getPostfields())) {
    $msg = 'You can only choose get OR post fields.';
    \Drupal::logger('tweets')->notice($msg);
  }

  $getfields = preg_replace('/^\?/', '', explode('&', $string));
  $params = [];
  foreach ($getfields as $field) {
    if ($field !== '') {
      list($key, $value) = explode('=', $field);
      $params[$key] = $value;
    }
  }
  $this->getfield = '?' . http_build_query($params);

  return $this;
}

/**
* Get getfield string (simple getter)
*
* @return string
*/
public function getGetfield() {
  return $this->getfield;
}

/**
* Get postfields array (simple getter)
*
* @return array
*/
public function getPostfields() {
  return $this->postfields;
}

/**
* Build the Oauth object using params set in construct and additionals.
* passed to this method. For v1.1, see: https://dev.twitter.com/docs/api/1.1.
*
* @param string
* @param string
*
* @throws \Exception
*
* @return \TwitterAPIExchange
*/
public function buildOauth($url, $requestMethod) {
  if (!in_array(strtolower($requestMethod), ['post', 'get'])) {
     \Drupal::logger('tweets')->notice('Request method must be either POST or GET');
  }

  $consumerKey = $this->consumer_key;
  $consumerSecret = $this->consumer_secret;
  $oauthAccessToken = $this->oauth_access_token;
  $oauthAccessTokenSecret = $this->oauth_access_token_secret;

  $oauth = [
    'oauth_consumer_key' => $consumerKey,
    'oauth_nonce' => time(),
    'oauth_signature_method' => 'HMAC-SHA1',
    'oauth_token' => $oauthAccessToken,
    'oauth_timestamp' => time(),
    'oauth_version' => '1.0',
  ];

  $getfield = $this->getGetfield();

  if (!is_null($getfield)) {
    $getfields = str_replace('?', '', explode('&', $getfield));
    foreach ($getfields as $g) {
      $split = explode('=', $g);
      // In case a null is passed through.
      if (isset($split[1])) {
        $oauth[$split[0]] = urldecode($split[1]);
      }
    }
  }

  $postfields = $this->getPostfields();
  if (!is_null($postfields)) {
    foreach ($postfields as $key => $value) {
      $oauth[$key] = $value;
    }
  }
  $base_info = $this->buildBaseString($url, $requestMethod, $oauth);
  $composite_key = rawurlencode($consumerSecret) . '&' . rawurlencode($oauthAccessTokenSecret);
  $oauth_signature = base64_encode(hash_hmac('sha1', $base_info, $composite_key, TRUE));
  $oauth['oauth_signature'] = $oauth_signature;

  $this->url = $url;
  $this->requestMethod = $requestMethod;
  $this->oauth = $oauth;

  return $this;
}

/**
* Perform the actual data retrieval from the API.
*
* @param bool $return
*   If true, returns data. This is left in for backward compatibility reasons.
* @param array
*
* @throws \Exception
*
* @return string
*/
public function performRequest($return = TRUE, $curlOptions = []) {
  if (!is_bool($return)) {
     \Drupal::logger('tweets')->notice('performRequest parameter must be true or false');
  }
  $header = [$this->buildAuthorizationHeader($this->oauth), 'Expect:'];
  $getfield = $this->getGetfield();
  $postfields = $this->getPostfields();
  $options = [
    CURLOPT_HTTPHEADER => $header,
    CURLOPT_HEADER => FALSE,
    CURLOPT_URL => $this->url,
    CURLOPT_RETURNTRANSFER => TRUE,
    CURLOPT_TIMEOUT => 10,
  ] + $curlOptions;
  if (!is_null($postfields)) {
    $options[CURLOPT_POSTFIELDS] = http_build_query($postfields);
  }
  else {
    if ($getfield !== '') {
      $options[CURLOPT_URL] .= $getfield;
    }
  }
  $feed = curl_init();
  curl_setopt_array($feed, $options);
  $json = curl_exec($feed);
  if (($error = curl_error($feed)) !== '') {
    curl_close($feed);
    throw new \Exception($error);
  }
  curl_close($feed);
  return $json;
}

/**
* Private method to generate the base string used by cURL.
*
* @param string $baseURI
*   baseURI.
* @param string $method
*   method.
* @param array $params
*   params.
*
* @return string
*   Built base string.
*/
private function buildBaseString($baseURI, $method, $params) {
  $return = [];
  ksort($params);
  foreach ($params as $key => $value) {
    $return[] = rawurlencode($key) . '=' . rawurlencode($value);
  }

  return $method . "&" . rawurlencode($baseURI) . '&' . rawurlencode(implode('&', $return));
}

/**
* Private method to generate authorization header used by cURL.
*
* @param array $oauth
*   Array of oauth data generated by buildOauth().
* @return string $return
*   Header used by cURL for request.
*/
private function buildAuthorizationHeader(array $oauth) {
  $return = 'Authorization: OAuth ';
  $values = [];

  foreach ($oauth as $key => $value) {
    if (in_array($key, [
      'oauth_consumer_key',
      'oauth_nonce',
      'oauth_signature',
      'oauth_signature_method',
      'oauth_timestamp',
      'oauth_token',
      'oauth_version',
    ])) {
      $values[] = "$key=\"" . rawurlencode($value) . "\"";
    }
  }

  $return .= implode(', ', $values);
  return $return;
}

/**
* Helper method to perform our request.
*
* @param string $url
* @param string $method
* @param string $data
* @param array $curlOptions
*
* @throws \Exception
*
* @return string The json response from the server
*/
public function request($url, $method = 'get', $data = NULL, $curlOptions = []) {
  if (strtolower($method) === 'get') {
    $this->setGetfield($data);
  }
  else {
    $this->setPostfields($data);
  }
  return $this->buildOauth($url, $method)->performRequest(TRUE, $curlOptions);
}

}
