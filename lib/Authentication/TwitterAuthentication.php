<?php
/**
  * @package Authentication
  */

/**
  * @package Authentication
  */
class TwitterAuthentication extends OAuthAuthentication
{
    protected $authorityClass = 'twitter';
    protected $requestTokenURL = 'https://api.twitter.com/oauth/request_token';
    protected $accessTokenURL = 'https://api.twitter.com/oauth/access_token';
	protected $API_URL = 'https://api.twitter.com/1';

    protected function reset($hard=false)
    {
        parent::reset($hard);
        if ($hard) {
            // this where we would log out of twitter
        }
    }
    
    public function validate(&$error) {
        return true;
    }
    
    protected function getUserFromArray(array $array)
    {
        if (isset($array['screen_name'])) {
            return $this->getUser($array['screen_name']);
        }
        
        return false;
    }
	
    public function getUser($login)
    {
        if (empty($login)) {
            return new AnonymousUser();       
        }

        //use the cache if available
        if ($this->useCache) {
            $cacheFilename = "user_$login";
            if ($this->cache === NULL) {
                  $this->cache = new DiskCache(CACHE_DIR . "/Twitter", $this->cacheLifetime, TRUE);
                  $this->cache->setSuffix('.json');
                  $this->cache->preserveFormat();
            }

            if ($this->cache->isFresh($cacheFilename)) {
                $data = $this->cache->read($cacheFilename);
            } else {
                //cache isn't fresh, load the data
                if ($data = $this->oauthRequest('GET', $this->API_URL .'/users/show.json', array('screen_name'=>$login))) {
                    $this->cache->write($data, $cacheFilename);
                }
                
            }
        } else {
            //load the data
            $data = $this->oauthRequest('GET', $this->API_URL . '/users/show.json', array('screen_name'=>$login));
        }
        
		// make the call
		if ($data) {
            $json = @json_decode($data, true);

            if (isset($json['screen_name'])) {
                $user = new TwitterUser($this);
                $user->setTwitterUserID($json['id']);
                $user->setUserID($json['screen_name']);
                $user->setFullName($json['name']);
                return $user;
            }        
        }

        return false;
    }
    
    protected function getAuthURL(array $params)
    {
        $url = "https://api.twitter.com/oauth/authenticate?" . http_build_query(array(
            'oauth_token'=>$this->token
            )
        );
        return $url;
    }
    
    public function init($args)
    {
        parent::init($args);
        $args = is_array($args) ? $args : array();
        if (!isset($args['OAUTH_CONSUMER_KEY'], $args['OAUTH_CONSUMER_SECRET']) || 
            strlen($args['OAUTH_CONSUMER_KEY'])==0 || strlen($args['OAUTH_CONSUMER_SECRET'])==0) {
            throw new Exception("Twitter Consumer key and secret not set");
        }
        
        $this->consumer_key = $args['OAUTH_CONSUMER_KEY'];
        $this->consumer_secret = $args['OAUTH_CONSUMER_SECRET'];
    }
}

/**
  * @package Authentication
  */
class TwitterUser extends OAuthUser
{
    protected $twitter_userID;
    
    public function setTwitterUserID($userID)
    {
        $this->twitter_userID = $userID;
    }

    public function getTwitterUserID()
    {
        return $this->twitter_userID;
    }

    protected function standardAttributes()
    {
        return array_merge(parent::standardAttributes(), array('twitter_userID'));
    }
    
}
