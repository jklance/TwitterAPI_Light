<?php
class TwitterAPILight {
    const API_HOST               = 'api.twitter.com/';

    const API_SEARCH_ENDPOINT    = '1.1/search/tweets.json?';
    const SEARCH_CACHE_FILE      = 'api_search.cache';
    const SEARCH_CACHE_LIFESPAN  = 180;
    
    const API_TWEET_ENDPOINT     = '1.1/statuses/user_timeline.json?';
    const TWEET_CACHE_FILE       = 'api_tweet.cache';
    const TWEET_CACHE_LIFESPAN   = 180;

    const API_TOKEN_ENDPOINT     = 'oauth2/token';
    const BEARER_CACHE_FILE      = 'api_bearer.cache';
    const BEARER_CACHE_LIFESPAN  = 3600;

    private $_errors = array();

    private $_consumer = array(
        'key'       => null,
        'secret'    => null,
        'encoded'   => null,
    );
    private $_bearer = array(
        'type'      => null,
        'token'     => null,
    );
    private $_tweets = array();

    /**
     * Constructor
     */
    public function __construct($ckey, $csec) {
        $this->setConsumerKey($ckey);
        $this->setConsumerSecret($csec);
    }

    public function getErrors() {
        return $this->_errors;
    }

    public function setConsumerKey($ckey) {
        if ($ckey) {
            $this->_consumer['key'] = $ckey;
        }
    }

    public function setConsumerSecret($csec) {
        if ($csec) {
            $this->_consumer['secret'] = $csec;
        }
    }

    public function setConsumer($consumer) {
        if ($consumer && isset($consumer['key']) && isset($consumer['secret'])) {
            $this->_consumer['key']     = $consumer['key'];
            $this->_consumer['secret']  = $consumer['secret'];
        }
    }

    public function applicationAuth() {
        $this->_encodeConsumer();
        $this->_retrieveBearerToken();
    }

    public function getUserTweets($screenName, $tweetCount = 10, $getReplies = false) {
        $response = $this->_getDataFromCache(TwitterAPILight::TWEET_CACHE_FILE, TwitterAPILight::TWEET_CACHE_LIFESPAN);
        if (!$response) {
            $response = $this->_getTweetsFromWeb($screenName, $tweetCount, $getReplies);
        }

        $this->_parseTweetsFromJSON($response); 
        return $this->_tweets;
    }        

    public function getSearchTweets($searchTerms, $resultCount = 10) {
        $response = $this->_getDataFromCache(TwitterAPILight::SEARCH_CACHE_FILE, TwitterAPILight::SEARCH_CACHE_LIFESPAN);
        if (!$response) {
            $response = $this->_getSearchFromWeb($searchTerms, $resultCount);
        }

        $this->_parseSearchesFromJSON($response);
        return $this->_tweets;
    }    

    private function _parseSearchesFromJSON($jsonData) {
        $this->_tweets = array();
        $data = null;
        if ($jsonData) {
            $data = json_decode($jsonData, true);
            foreach($data['statuses'] as $k => $v) {
                array_push($this->_tweets, array(
                    'created'       => $v['created_at'],
                    'id'            => $v['id_str'],
                    'content'       => $v['text'],
                    'repied_to'     => $v['in_reply_to_status_id_str'],
                    'author'        => $v['user']['screen_name'],
                    'author_name'   => $v['user']['name'],
                ));
            }
        }
    }

    private function _parseTweetsFromJSON($jsonData) {
        $this->_tweets = array();
        $data = null;
        if ($jsonData) {
            $data = json_decode($jsonData, true);
            foreach($data as $k => $v) {
                array_push($this->_tweets, array(
                    'created'       => $v['created_at'],
                    'id'            => $v['id_str'],
                    'content'       => $v['text'],
                    'repied_to'     => $v['in_reply_to_status_id_str'],
                    'author'        => $v['user']['screen_name'],
                    'author_name'   => $v['user']['name'],
                ));
            }
        }
    }

    private function _encodeConsumer() {
        $this->_consumer['key']     = urlencode($this->_consumer['key']);
        $this->_consumer['secret']  = urlencode($this->_consumer['secret']);
        $this->_consumer['encoded'] = base64_encode(
            $this->_consumer['key'] . ':' . $this->_consumer['secret']
        );
    }

    private function _retrieveBearerToken($security=true) {
        $response = $this->_getDataFromCache(TwitterAPILight::BEARER_CACHE_FILE, TwitterAPILight::BEARER_CACHE_LIFESPAN);
        if (!$response) {
            $response = $this->_getTokenFromWeb($security);
        }

        $this->_parseTokenFromJSON($response);
    }

    private function _getDataFromCache($cacheFile, $cacheLifespan) {
        $data = null;
        if (file_exists($cacheFile) && !$this->_isCacheExpired($cacheFile, $cacheLifespan)) {
            $fh = fopen($cacheFile, 'r');
            if ($fh) {
                $data = fread($fh, filesize($cacheFile));    
                fclose($fh);
            }
        }
        return $data;
    }

    private function _isCacheExpired($cacheFile, $cacheLifespan) {
        $fileTime = filemtime($cacheFile);
        $sysTime = time();
        
        if ($fileTime && ($sysTime - $fileTime < $cacheLifespan)) {
            return false;
        }
        return true;
    }

    private function _getSearchFromWeb($searchTerms, $tweetCount, $security=true) {
        $security = $this->_getSecurity($security);
        
        $searchString = null;
        if (is_array($searchTerms)) {
            foreach($searchTerms as $k => $v) {
                $searchString .= $v . ' OR ';
            }
        } else {
            $searchString = $searchTerms;
        }
        $searchString = urlencode($searchString);
        
        $url = $security['protocol'] . TwitterAPILight::API_HOST. TwitterAPILight::API_SEARCH_ENDPOINT;
        $url .= 'q=' . $searchString; 
        $url .= '&count='       . $tweetCount;

        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL             => $url,
            CURLOPT_PORT            => $security['port'],
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_HTTPHEADER      => array(
                'Authorization: ' . $this->_bearer['type'] . ' ' . $this->_bearer['token'],
            ),
        ));
        $response = curl_exec($ch);
        $this->_writeDataToCache($response, TwitterAPILight::SEARCH_CACHE_FILE);

        array_push($this->_errors, curl_error($ch));
        return $response;
    }

    private function _getTweetsFromWeb($screenName, $tweetCount, $getReplies, $security=true) {
        $security = $this->_getSecurity($security);
    
        $url = $security['protocol'] . TwitterAPILight::API_HOST. TwitterAPILight::API_TWEET_ENDPOINT;
        $url .= 'screen_name='  . $screenName;
        $url .= '&count='       . $tweetCount;
        $url .= '&include_rts=' . $getReplies;        

        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL             => $url,
            CURLOPT_PORT            => $security['port'],
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_HTTPHEADER      => array(
                'Authorization: ' . $this->_bearer['type'] . ' ' . $this->_bearer['token'],
            ),
        ));
        $response = curl_exec($ch);
        $this->_writeDataToCache($response, TwitterAPILight::TWEET_CACHE_FILE);

        array_push($this->_errors, curl_error($ch));
        return $response;
    }

    private function _getTokenFromWeb($security=true) {
        $security = $this->_getSecurity($security);

        $url = $security['protocol'] . TwitterAPILight::API_HOST . TwitterAPILight::API_TOKEN_ENDPOINT;
        
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL             => $url,
            CURLOPT_PORT            => $security['port'],
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_POST            => 1,
            CURLOPT_POSTFIELDS      => "grant_type=client_credentials",
            CURLOPT_HTTPHEADER      => array(
                'Authorization: Basic ' . $this->_consumer['encoded'],
                'Content-Type: application/x-www-form-urlencoded;charset=UTF-8'
            ),
        ));
        $response = curl_exec($ch);
        $this->_writeDataToCache($response, TwitterAPILight::BEARER_CACHE_FILE);

        array_push($this->_errors, curl_error($ch));
        return $response;
    }

    private function _writeDataToCache($data, $cacheFile) {
        $fh = fopen($cacheFile, 'w');
        if ($fh && $data) {
            fwrite($fh, $data);
            fclose($fh);
        }
    }

    private function _parseTokenFromJSON($jsonData) {
        if ($jsonData) {
            $data = json_decode($jsonData, true);
            if (isset($data['token_type'])) {
                $this->_bearer['type']  = $data['token_type'];
            } else {
                $this->_bearer['type']  = 'bearer';
            }
            if (isset($data['access_token'])) {
                $this->_bearer['token'] = $data['access_token'];
            } 
        }
    }

    private function _getSecurity($security=true) {
        $security = array(
            'port' => '80',
            'protocol' => 'http://',
        );
        if ($security) {
            $security['port']       = '443';
            $security['protocol']   = 'https://';
        }
        return $security;
    }
}
