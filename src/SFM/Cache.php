<?php

/**
 *  Class for work with daemons that use memcache protocol. Implements tags system for cache control
 */
abstract class SFM_Cache implements SFM_MonitorableInterface
{
    const KEY_DILIMITER = '@';

    const KEY_VALUE = 'value';
    const KEY_TAGS  = 'tags';
    const KEY_EXPIRES  = 'expires';

    const FORCE_TIMEOUT = 1;

    /**
     * Memcached object
     *
     * @var Memcached
     */
    protected $driver;

    /** @var SFM_Config_Cache */
    protected $config;

    protected $projectPrefix = '';

    /**
     * @var SFM_Cache_Transaction
     */
    protected $transaction;

    /**
     * @var SFM_MonitorInterface
     */
    protected $monitor;

    /**
     * @param SFM_Config_Cache $config
     * @return $this
     */
    public function init(SFM_Config_Cache $config)
    {
        $this->config = $config;

        return $this;
    }

    public function connect()
    {
        if (is_null($this->config)) {
            throw new SFM_Exception_DB("SFM_Cache is not configured");
        }

        if ($this->config->isDisabled()) {
            $this->driver = new SFM_Cache_Dummy();
        } else {
            $this->driver = new Memcached();

            if (!$this->driver->addServer($this->config->getHost(), $this->config->getPort(), true)) {
                throw new SFM_Exception_Memcached('Can\'t connect to server '.$this->config->getHost().':'.$this->config->getPort());
            }
        }
    }

    public function __construct()
    {
        $this->transaction = new SFM_Cache_Transaction($this);
    }

    /**
     * @param SFM_MonitorInterface $monitor
     */
    public function setMonitor(SFM_MonitorInterface $monitor)
    {
        $this->monitor = $monitor;
    }

    /**
     * Get value by key from cache
     *
     * @param string $key
     * @return mixed
     */
    public function get($key)
    {
        if ($this->transaction->isStarted() && $this->transaction->isKeyDeleted($key)) {
            $result = null;
        } else {
            $arr = unserialize($this->_get($key));
            if (!is_array($arr)) {
                return null;
            }
            $result = $this->getValidObject($arr);
            if($result === null) {
                //If the object is invalid, remove it from cache
                $this->_delete($key);
            }
        }

        return $result;
    }

    /**
     * Get object by array of keys
     * @param array $keys
     * @return array|null
     */
    public function getMulti( array $keys )
    {
        if ($this->transaction->isStarted()) {
            foreach ($keys as $i => $key) {
                if ($this->transaction->isKeyDeleted($key)) {
                    unset($keys[$i]);
                }
            }
        }

        $values = $this->_getMulti($keys);
        $result = array();
        if( false != $values ) {
            foreach ($values as $item) {
                $obj = $this->getValidObject(unserialize($item));
                if( null != $obj) {
                    $result[] = $obj;
                }
            }
        }

        if(sizeof($result)!=0) {

            return $result;
        } else {

            return null;
        }
    }

    /**
     * Save value to cache
     *
     * @param SFM_Business $value
     */
    public function set(SFM_Business $value)
    {
        if ($this->transaction->isStarted()) {
            $this->transaction->logBusiness($value);
        } else {

            $data = array(
                self::KEY_VALUE => serialize($value),
                self::KEY_TAGS  => $this->getTags($value->getCacheTags()),
                self::KEY_EXPIRES  => $value->getExpires(),
            );

            $this->_set($value->getCacheKey(), $data, $value->getExpires());
        }
    }

    /**
     * @param string $key
     * @param mixed $value
     * @param int $expiration
     * @return bool
     */
    public function setRaw($key, $value, $expiration = 0)
    {
        $key = $this->generateKey($key);

        if ($this->transaction->isStarted()) {
            $result = $this->transaction->logRaw($key, $value, $expiration);
        } else {
            $time = microtime(true);
            $result = $this->driver->set($key, $value, $expiration);
            $this->checkCacheIsAlive($time);
        }

        return $result;
    }

    /**
     * Wrapper to SetMulti
     * Existing tags aren't reseted
     *
     * @param array[SFM_Entities] $items
     * @param int $expiration
     */
    public function setMulti(array $items, $expiration=0)
    {
        if ($this->transaction->isStarted()) {
            $this->transaction->logMulti($items, $expiration);
        } else {

            $arr = array();
            /** @var $businessObj SFM_Business */
            foreach ($items as $businessObj) {
                $arr[$businessObj->getCacheKey()] = serialize( array(
                    self::KEY_VALUE => serialize($businessObj),
                    self::KEY_TAGS  => $this->getTags($businessObj->getCacheTags()),
                    self::KEY_EXPIRES  => $expiration,
                ));
           }

            $this->_setMulti($arr, $expiration);
        }
    }

    /**
     * Deletes value by its key
     *
     * @param string $key Cache key
     * @return bool
     */
    public function delete($key)
    {
        if ($this->transaction->isStarted()) {
            $this->transaction->logDeleted($key);
            $result = true;
        } else {
            $result = $this->_delete($key);
        }

        return $result;
    }



    /**
     * Get tag values by keys
     *
     * @param array $key
     * @return array
     */
    protected function getTags($keys)
    {
        $keys = (array) $keys;
        $values = array();
        $tagValues = array();
        $tagKeys = array();
        foreach ($keys as $key) {
            $tagKeys[] = $this->getTagByKey($key);
        }
        $tagValues = $this->_getMulti($tagKeys);
        if($tagValues === null)
            $tagValues = array();

        $i = 0;
        foreach($tagValues as $tagValue) {
            $key = $keys[$i];
            $value = unserialize($tagValue);
            if ( false === $value) {
                $value = $this->resetTags($key);
            }
            $values[$key] = $value;
            $i++;
        }
        return $values;
    }

    /**
     * Resets tag values and returns new values
     * The return type depends on type of $keys
     *
     * @param array $keys
     * @return array
     */
    public function resetTags($keys)
    {
        $keys = (array) $keys;
        $values = array();
        $tagValues = array();
        foreach ($keys as $key) {
            $tag = $this->getTagByKey($key);
            $values [$key]= $value = microtime(true);
            $tagValues[$tag] = serialize($value);
        }
        if(!empty($tagValues)) {
            $this->_setMulti($tagValues);
        }
        return $values;
    }

    /**
     * Wrapper over Memcached set method
     *
     * @param string $key
     * @param mixed $value
     * @param int $expiration
     */
    protected function _set($key, $value, $expiration=0)
    {
        $value = serialize($value);
        $key = $this->generateKey($key);
        if ($this->monitor !== null) {
            $timer = $this->monitor->createTimer(array('db' => get_class($this), 'operation' => 'set'));
        }

        $time = microtime(true);
        $this->driver->set($key, $value, $expiration);
        $this->checkCacheIsAlive($time);
        if (isset($timer)) {
            $timer->stop();
        }
    }

    protected function checkCacheIsAlive($time)
    {
        if (microtime(true) - $time > self::FORCE_TIMEOUT) {
            $this->driver = new SFM_Cache_Dummy();
        }
    }

    /**
     * Wrapper over Memcached setMulti method
     *
     * @param array $items
     * @param int $expiration
     */
    protected function _setMulti($items, $expiration=0)
    {
        $resultItems = array();
        foreach($items as $key => $value)
        {
            $key = $this->generateKey($key);
            $resultItems[$key] = $value;
        }
        if ($this->monitor !== null) {
            $timer = $this->monitor->createTimer(array('db' => get_class($this), 'operation' => 'setMulti'));
        }
        $time = microtime(true);
        $this->driver->setMulti($resultItems, $expiration);
        $this->checkCacheIsAlive($time);

        if (isset($timer)) {
            $timer->stop();
        }
    }

    /**
     * Wrapper over Memcached get method
     *
     * @param string $key
     * @return mixed|null
     */
    protected function _get($key)
    {
        if ($this->monitor !== null) {
            $timer = $this->monitor->createTimer(array('db' => get_class($this), 'operation' => 'get'));
        }

        $time = microtime(true);
        $value = $this->driver->get($this->generateKey($key));
        $this->checkCacheIsAlive($time);

        if (isset($timer)) {
            $timer->stop();
        }
        return ($value === false) ? null : $value;
    }

    /**
     * Wrapper over Memcached getMulti method
     *
     * @param array $keys
     * @return mixed|null
     */
    protected function _getMulti( array $keys )
    {
        foreach($keys as &$key)
        {
            $key = $this->generateKey($key);
        }
        if ($this->monitor !== null) {
            $timer = $this->monitor->createTimer(array('db' => get_class($this), 'operation' => 'getMulti'));
        }

        $time = microtime(true);
        $values = $this->driver->getMulti($keys);
        $this->checkCacheIsAlive($time);

        if (isset($timer)) {
            $timer->stop();
        }

        return ($values === false) ? null : $values;
    }
    /**
     * Wrapper over Cache delete method
     *
     * @param string $key key to delete
     * @return bool
     */
    protected function _delete($key)
    {
        if ($this->monitor !== null) {
            $timer = $this->monitor->createTimer(array('db' => get_class($this), 'operation' => 'delete'));
        }

        $time = microtime(true);
        $result = $this->driver->delete($this->generateKey($key));
        $this->checkCacheIsAlive($time);

        if (isset($timer)) {
            $timer->stop();
        }
        return $result;
    }

    /**
     * Flushes all data in Memcached.
     * For debug purposes only!
     *
     */
    public function flush()
    {
        if ($this->monitor !== null) {
            $timer = $this->monitor->createTimer(array('db' => get_class($this), 'operation' => 'flush'));
        }

        $time = microtime(true);
        $this->driver->flush();
        $this->checkCacheIsAlive($time);

        if (isset($timer)) {
            $timer->stop();
        }
    }

    public function getDriver()
    {
        return $this->driver;
    }

        /**
     * Returns key for storing tags.
     * Since tag keys must differ from object keys, method concatinates some prefix
     *
     * @param string $key Original name of tag. Can be the same as Entity Cache key
     * @return string
     */
    protected function getTagByKey($key)
    {
        return $this->generateKey('Tag' . self::KEY_DILIMITER . $key);
    }

    protected function getValidObject(array $raw)
    {
        $oldTagValues = (array) $raw[self::KEY_TAGS];

        $newTagValues = $this->getTags(array_keys($oldTagValues));
        //expiration objects should expire without tags
        if($oldTagValues == $newTagValues || $raw[self::KEY_EXPIRES]) {
            return unserialize($raw[self::KEY_VALUE]);
        } else {
            return null;
        }
    }

    protected function generateKey($key)
    {
        return md5($this->config->getPrefix().self::KEY_DILIMITER.$key);
    }

    public function beginTransaction()
    {
        if ($this->monitor !== null) {
            $timer = $this->monitor->createTimer(array('db' => get_class($this), 'operation' => 'beginTransaction'));
        }

        $this->transaction->begin();

        if (isset($timer)) {
            $timer->stop();
        }
    }

    /**
     * @return bool
     */
    public function isTransaction()
    {
        return $this->transaction->isStarted();
    }

    public function commitTransaction()
    {
        if ($this->monitor !== null) {
            $timer = $this->monitor->createTimer(array('db' => get_class($this), 'operation' => 'commitTransaction'));
        }

        $this->transaction->commit();

        if (isset($timer)) {
            $timer->stop();
        }
    }

    public function rollbackTransaction()
    {
        if ($this->monitor !== null) {
            $timer = $this->monitor->createTimer(array('db' => get_class($this), 'operation' => 'rollbackTransaction'));
        }

        $this->transaction->rollback();

        if (isset($timer)) {
            $timer->stop();
        }
    }
}