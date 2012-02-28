<?php

/**
  * Transit View Data Model
  * @package Transit
  */

class TransitViewDataModel extends DataModel implements TransitDataModelInterface
{
    protected $config = array();
    protected $models = array();
    protected $daemonMode = false;
    protected $cache = null;
    protected $globalIDSeparator = null;
    
    const DEFAULT_TRANSIT_CACHE_GROUP = 'View';
    
    // Do not call parent!!!
    protected function init($config) {
        if (isset($args['DAEMON_MODE'])) {
            $this->daemonMode = $args['DAEMON_MODE'];
            unset($args['DAEMON_MODE']); // Make sure this doesn't confuse TransitConfig class
        }
        
        $this->config = new TransitConfig($config);

        $this->setDebugMode(Kurogo::getOptionalSiteVar('DEBUG_MODE', false));
        $this->globalIDSeparator = Kurogo::getOptionalSiteVar('TRANSIT_GLOBAL_ID_SEPARATOR', '__');
        
        foreach ($this->config->getModelIDs() as $modelID) {
            $model = array(
                'system' => $this->config->getSystem($modelID),
                'live'   => false,
                'static' => false,
            );
            
            if ($this->config->hasLiveModel($modelID)) {
                $class                   = $this->config->getLiveModelClass($modelID);
                $args                    = $this->config->getLiveModelArgs($modelID);
                $args['FIELD_OVERRIDES'] = $this->config->getLiveModelOverrides($modelID);
                $args['DAEMON_MODE']     = $this->daemonMode;
                
                $model['live'] = DataModel::factory($class, $args);
            }
            
            if ($this->config->hasStaticModel($modelID)) {
                $class                   = $this->config->getStaticModelClass($modelID);
                $args                    = $this->config->getStaticModelArgs($modelID);
                $args['FIELD_OVERRIDES'] = $this->config->getStaticModelOverrides($modelID);
                $args['DAEMON_MODE']     = $this->daemonMode;
                
                $model['static'] = DataModel::factory($class, $args);
            }
            
            $this->models[$modelID] = $model;
        }
        
        $cacheClass = Kurogo::getOptionalSiteVar('TRANSIT_VIEW_CACHE_CLASS', 'DataCache');
        $this->cache = DataCache::factory($cacheClass, array(
            'CACHE_FOLDER' => Kurogo::getOptionalSiteVar('TRANSIT_CACHE_DIR', 'Transit'),
        ));
        
        $this->cache->setCacheGroup('View');
        
        $cacheLifetime = Kurogo::getOptionalSiteVar('TRANSIT_VIEW_CACHE_TIMEOUT', 20);
        if ($this->daemonMode) {
            // daemons should load cached files aggressively to beat user page loads
            $cacheLifetime -= 300;
            if ($cacheLifetime < 0) { $cacheLifetime = 0; }
        }
        $this->cache->setCacheLifetime($cacheLifetime);
    }
    
    protected function getCachedViewForKey($cacheKey) {
        $view = $this->cache->get($cacheKey);
        return $view ? $view : array();
    }
    
    protected function cacheViewForKey($cacheKey, $view) {
        $this->cache->set($cacheKey, $view);
    }
    
    public function refreshLiveServices() {
        foreach ($this->config->getModelIDs() as $modelID) {
            if ($this->config->hasLiveModel($modelID)) {            
                unset($this->models[$modelID]['live']);
                
                $class                   = $this->config->getLiveModelClass($modelID);
                $args                    = $this->config->getLiveModelArgs($modelID);
                $args['FIELD_OVERRIDES'] = $this->config->getLiveModelOverrides($modelID);
                $args['DAEMON_MODE']     = $this->daemonMode;
                
                $this->models[$modelID]['live'] = TransitDataModel::factory($class, $args);
            }
        }
    }
    
    public function getStopInfoForRoute($globalRouteID, $globalStopID) {  
        $stopInfo = array();
        $cacheKey = "stopInfoForRoute.$globalRouteID.$globalStopID";
        
        if (!$stopInfo = $this->getCachedViewForKey($cacheKey)) {
            list($system, $routeID) = $this->getRealID($globalRouteID);
            list($system, $stopID)  = $this->getRealID($globalStopID);
            $model = $this->modelForRoute($system, $routeID);
            
            if ($model['live']) {
                $stopInfo = $model['live']->getStopInfoForRoute($routeID, $stopID);
            }
            
            if ($model['static']) {
                $staticStopInfo = $model['static']->getStopInfoForRoute($routeID, $stopID);
            }
            
            if (!$stopInfo) {
                $stopInfo = $staticStopInfo;
            }
            
            if ($stopInfo) {
                if (!isset($stopInfo['arrives']) || $staticStopInfo['arrives'] < $stopInfo['arrives']) {
                    $stopInfo['arrives'] = $staticStopInfo['arrives'];
                }
                if (!isset($stopInfo['predictions'])) {
                    $stopInfo['predictions'] = $staticStopInfo['predictions'];
                  
                } else if (count($staticStopInfo['predictions'])) {
                    $stopInfo['predictions'] = array_merge($stopInfo['predictions'], $staticStopInfo['predictions']);
                    
                    $stopInfo['predictions'] = array_unique($stopInfo['predictions']);
                    sort($stopInfo['predictions']);
                }
            }
            $this->cacheViewForKey($cacheKey, $stopInfo);
        }
        
        return $stopInfo;
    }
    
    public function getStopInfo($globalStopID) {
        $stopInfo = array();
        $cacheKey = "stopInfo.$globalStopID";
        
        if (!$stopInfo = $this->getCachedViewForKey($cacheKey)) {
            list($system, $stopID) = $this->getRealID($globalStopID);
          
            foreach ($this->modelsForStop($system, $stopID) as $model) {
                $modelInfo = false;
                
                if ($model['live']) {
                    $modelInfo = $model['live']->getStopInfo($stopID);
                }
                
                if ($model['static']) {
                    $staticModelInfo = $model['static']->getStopInfo($stopID);
                }
                
                if (!$modelInfo) {
                    $modelInfo = $staticModelInfo;
                } else if (isset($staticModelInfo['routes'])) {
                    // if live model returns routes that are actually not in service
                    foreach (array_keys($modelInfo['routes']) as $routeID) {
                        if (!isset($staticModelInfo['routes'][$routeID])) {
                            unset($modelInfo['routes'][$routeID]);
                        }
                    }
            
                    foreach ($staticModelInfo['routes'] as $routeID => $routeInfo) {
                        if (!isset($modelInfo['routes'][$routeID]) ||
                            !isset($modelInfo['routes'][$routeID]['predictions'])) {
                            $modelInfo['routes'][$routeID] = $routeInfo;
                        }
                        
                        // Use static route names if available
                        if (isset($routeInfo['name']) && $routeInfo['name']) {
                            $modelInfo['routes'][$routeID]['name'] = $routeInfo['name'];
                        }
                    }
                    
                    // Use static stop names if available
                    if (isset($staticModelInfo['name']) && $staticModelInfo['name']) {
                        $modelInfo['name'] = $staticModelInfo['name'];
                    }
                }
                
                if ($modelInfo) {
                    if (!count($stopInfo)) {
                        $stopInfo = $modelInfo;
                    } else {
                        foreach ($modelInfo['routes'] as $routeID => $stopTimes) {
                            if (!isset($stopInfo['routes'][$routeID])) {
                                $stopInfo['routes'][$routeID] = $stopTimes;
                            } else {
                                if (!isset($stopTimes['predictions'])) {
                                    $stopInfo['routes'][$routeID]['predictions'] = $stopTimes['predictions'];
                                
                                } else if (count($stopTimes['predictions'])) {
                                    $stopInfo['routes'][$routeID]['predictions'] = array_merge(
                                        $stopInfo['routes'][$routeID]['predictions'], $stopTimes['predictions']);
                                    
                                    $stopInfo['routes'][$routeID]['predictions'] = 
                                        array_unique($stopInfo['routes'][$routeID]['predictions']);
                                    sort($stopInfo['routes'][$routeID]['predictions']);
                                }
                            }
                        }
                    }
                }
            }
            $this->remapStopInfo($system, $stopInfo);
            
            $this->cacheViewForKey($cacheKey, $stopInfo);
        }
        return $stopInfo;
    }
  
    public function getMapImageForStop($globalStopID, $width=270, $height=270) {
        $image = false;
        list($system, $stopID) = $this->getRealID($globalStopID);
        $model = reset($this->modelsForStop($system, $stopID));
        
        if ($model['live']) {
            $image = $model['live']->getMapImageForStop($stopID, $width, $height);
        }
        
        if (!$image && $model['static']) {
            $image = $model['static']->getMapImageForStop($stopID, $width, $height);
        }
        
        return $image;
    }
  
    public function getMapImageForRoute($globalRouteID, $width=270, $height=270) {
        $image = false;
        list($system, $routeID) = $this->getRealID($globalRouteID);
        $model = $this->modelForRoute($system, $routeID);
        
        if ($model['live']) {
            $image = $model['live']->getMapImageForRoute($routeID, $width, $height);
        }
        
        if (!$image && $model['static']) {
            $image = $model['static']->getMapImageForRoute($routeID, $width, $height);
        }
        
        return $image;
    }
    
    public function getRouteInfo($globalRouteID, $time=null) {
        $routeInfo = array();
        $cacheKey = "routeInfo.$globalRouteID";
        
        if ($time != null || !$routeInfo = $this->getCachedViewForKey($cacheKey)) {
            list($system, $routeID) = $this->getRealID($globalRouteID);
            $model = $this->modelForRoute($system, $routeID);
            
            if ($model['live']) {
                $routeInfo = $model['live']->getRouteInfo($routeID, $time);
                if (count($routeInfo)) {
                    $routeInfo['live'] = true;
                }
            }
            
            if ($model['static']) {
                $staticRouteInfo = $model['static']->getRouteInfo($routeID, $time);
                
                if (!count($routeInfo)) {
                  $routeInfo = $staticRouteInfo;
                
                } else if (count($staticRouteInfo)) {
                  if (strlen($staticRouteInfo['name'])) {
                      // static name is better
                      $routeInfo['name'] = $staticRouteInfo['name'];
                  }
                  if (strlen($staticRouteInfo['description'])) {
                      // static description is better
                      $routeInfo['description'] = $staticRouteInfo['description'];
                  }
                  if ($staticRouteInfo['frequency'] != 0) { // prefer static
                      $routeInfo['frequency'] = $staticRouteInfo['frequency'];
                  }
                  if (!count($routeInfo['stops'])) {
                      $routeInfo['stops'] = $staticRouteInfo['stops'];
                  
                  } else {
                      // Use the static first stop, not the prediction first stop
                      // Use static stop names if available
                      $firstStop = reset(array_keys($staticRouteInfo['stops']));
                      $foundFirstStop = false;
                      $moveToEnd = array();
                      foreach ($routeInfo['stops'] as $stopID => $stop) {
                          $staticStopID = $stopID;
                        
                          if (!isset($staticRouteInfo['stops'][$staticStopID])) {
                              // NextBus sometimes has _ar suffixes on it.  Try stripping them
                              $parts = explode('_', $stopID);
                              if (isset($staticRouteInfo['stops'][$parts[0]])) {
                                  //error_log("Warning: static route does not have live stop id $stopID, using {$parts[0]}");
                                  $staticStopID = $parts[0];
                              }
                          }
                          
                          if (isset($staticRouteInfo['stops'][$staticStopID])) {
                              $routeInfo['stops'][$stopID]['name'] = $staticRouteInfo['stops'][$staticStopID]['name'];
                
                              if (!$stop['hasTiming'] && $staticRouteInfo['stops'][$staticStopID]['hasTiming']) {
                                  $routeInfo['stops'][$stopID]['arrives'] = $staticRouteInfo['stops'][$staticStopID]['arrives'];
                                  
                                  if (isset($staticRouteInfo['stops'][$staticStopID]['predictions'])) {
                                      $routeInfo['stops'][$stopID]['predictions'] = $staticRouteInfo['stops'][$staticStopID]['predictions'];
                                  } else {
                                      unset($routeInfo['stops'][$stopID]['predictions']);
                                  }
                              }
                          } else {
                              Kurogo::log(LOG_WARNING, "static route info does not have live stop id $stopID", 'transit');
                          }
                          
                          if ($foundFirstStop || TransitDataModel::isSameStop($stopID, $firstStop)) {
                              $foundFirstStop = true;
                          } else {
                              $moveToEnd[$stopID] = $stop;
                              unset($routeInfo['stops'][$stopID]);
                          }
                      }
                      $routeInfo['stops'] += $moveToEnd;
                      
                      uasort($routeInfo['stops'], array('TransitDataModel', 'sortStops'));
                    }
                }
            }
            
            if (count($routeInfo)) {
                $now = time();
                
                // Walk the stops to figure out which is upcoming
                $stopIDs     = array_keys($routeInfo['stops']);
                $firstStopID = reset($stopIDs);
                
                $firstStopPrevID  = end($stopIDs);
                if (TransitDataModel::isSameStop($firstStopID, $firstStopPrevID)) {
                    $firstStopPrevID = prev($stopIDs);
                }
                
                foreach ($stopIDs as $index => $stopID) {
                    if (!isset($routeInfo['stops'][$stopID]['upcoming'])) {
                        $arrives = $routeInfo['stops'][$stopID]['arrives'];
                  
                        if ($stopID == $firstStopID) {
                            $prevArrives = $routeInfo['stops'][$firstStopPrevID]['arrives'];
                        } else {
                            $prevArrives = $routeInfo['stops'][$stopIDs[$index-1]]['arrives'];
                        }
                  
                        // Suppress any soonest stops which are more than 2 hours from now
                        $routeInfo['stops'][$stopID]['upcoming'] = 
                            (abs($arrives - $now) < Kurogo::getSiteVar('TRANSIT_MAX_ARRIVAL_DELAY')) && 
                            $arrives <= $prevArrives;
                    }
                }
                
                $routeInfo['lastupdate'] = $now;
            }
            $this->remapRouteInfo($model['system'], $routeInfo);
      
            if ($time == null) {
                $this->cacheViewForKey($cacheKey, $routeInfo);
            }
        }
        
        return $routeInfo;    
    }
    
    public function getRoutePaths($globalRouteID) {
        $paths = array();
        
        list($system, $routeID) = $this->getRealID($globalRouteID);
        $model = $this->modelForRoute($system, $routeID);
        
        if ($model['live']) {
            $paths = $model['live']->getRoutePaths($routeID);
        } else if ($model['static']) {
            $paths = $model['static']->getRoutePaths($routeID);
        }
        
        return $paths;
    }
    
    public function getRouteVehicles($globalRouteID) {
        $vehicles = array();
        
        list($system, $routeID) = $this->getRealID($globalRouteID);
        $model = $this->modelForRoute($system, $routeID);
    
        if ($model['live']) {
            $vehicles = $model['live']->getRouteVehicles($routeID);
        } else if ($model['static']) {
            $vehicles = $model['static']->getRouteVehicles($routeID);
        }
        $vehicles = $this->remapVehicles($model['system'], $vehicles);
        
        return $vehicles;
    }
    
    public function getServiceInfoForRoute($globalRouteID) {
        $info = false;
        
        list($system, $routeID) = $this->getRealID($globalRouteID);
        $model = $this->modelForRoute($system, $routeID);
        
        if ($model['live']) {
            $info = $model['live']->getServiceInfoForRoute($routeID);
        }
        
        if (!$info && $model['static']) {
            $info = $model['static']->getServiceInfoForRoute($routeID);
        }
        
        return $info;
    }
    
    public function getRoutes($time=null) {
        $allRoutes = array();
        $cacheKey = 'allRoutes';
        
        if ($time != null || !$allRoutes = $this->getCachedViewForKey($cacheKey)) {
            foreach ($this->models as $model) {
                $routes = array();
                
                if ($model['live']) {
                    $routes = $this->remapRoutes($model['system'], $model['live']->getRoutes($time));
                }
                
                if ($model['static']) {
                    $staticRoutes = $this->remapRoutes($model['system'], $model['static']->getRoutes($time));
                    if (!count($routes)) {
                        $routes = $staticRoutes;
                    } else {
                        foreach ($routes as $routeID => $routeInfo) {
                          if (isset($staticRoutes[$routeID])) {
                              if (!$routeInfo['running']) {
                                  $routes[$routeID] = $staticRoutes[$routeID];
                              } else {
                                  // static name is better
                                  $routes[$routeID]['name'] = $staticRoutes[$routeID]['name'];
                                  $routes[$routeID]['description'] = $staticRoutes[$routeID]['description'];
                                  
                                  if ($staticRoutes[$routeID]['frequency'] != 0) {
                                      $routes[$routeID]['frequency'] = $staticRoutes[$routeID]['frequency'];
                                  }
                              }
                          }
                        }
                        // Pull in static routes with no live data
                        foreach ($staticRoutes as $routeID => $staticRouteInfo) {
                            if (!isset($routes[$routeID])) {
                                $routes[$routeID] = $staticRouteInfo;
                            }
                        }
                    }
                }
                $allRoutes += $routes;
            }
            if ($time == null) {
                $this->cacheViewForKey($cacheKey, $allRoutes);
            }
        }
        
        return $allRoutes;
    }
    
    // Private functions
    protected function remapStopInfo($system, &$stopInfo) {
        if (isset($stopInfo['routes'])) {
            $routes = array();
            foreach ($stopInfo['routes'] as $routeID => $routeInfo) {
                $routes[$this->getGlobalID($system, $routeID)] = $routeInfo;
            }
            $stopInfo['routes'] = $routes;
        }
    }
    
    protected function remapRouteInfo($system, &$routeInfo) {
        if (isset($routeInfo['stops'])) {
            $stops = array();
            foreach ($routeInfo['stops'] as $stopID => $stopInfo) {
                $stops[$this->getGlobalID($system, $stopID)] = $stopInfo;
            }
            $routeInfo['stops'] = $stops;
        }
        
        if (isset($routeInfo['directions'])) {
            // remap stop ids for schedule mode structures
            foreach ($routeInfo['directions'] as $d => $directionInfo) {
                foreach ($directionInfo['segments'] as $i => $segmentInfo) {
                    foreach ($segmentInfo['stops'] as $j => $stopInfo) {
                        $routeInfo['directions'][$d]['segments'][$i]['stops'][$j]['id'] = 
                            $this->getGlobalID($system, $stopInfo['id']);
                    }
                }
                foreach ($directionInfo['stops'] as $i => $stopInfo) {
                    $routeInfo['directions'][$d]['stops'][$i]['id'] = 
                        $this->getGlobalID($system, $stopInfo['id']);
                }
            }
        }
    }
    
    protected function remapRoutes($system, $routes) {
        $mappedRoutes = array();
        
        foreach ($routes as $routeID => $routeInfo) {
            $mappedRoutes[$this->getGlobalID($system, $routeID)] = $routeInfo;
        }
        
        return $mappedRoutes;
    }
    
    protected function remapVehicles($system, $vehicles) {
        $mappedVehicles = array();
        
        foreach ($vehicles as $vehicleID => $vehicleInfo) {
            if (isset($vehicleInfo['routeID'])) {
                $vehicleInfo['routeID'] = $this->getGlobalID($system, $vehicleInfo['routeID']);
            }
            if (isset($vehicleInfo['nextStop'])) {
                $vehicleInfo['nextStop'] = $this->getGlobalID($system, $vehicleInfo['nextStop']);
            }
            $mappedVehicles[$this->getGlobalID($system, $vehicleID)] = $vehicleInfo;
        }
        
        return $mappedVehicles;
    }
  
    protected function modelForRoute($system, $routeID) {
        foreach ($this->models as $model) {
            if ($model['system'] != $system) { continue; }
          
            if ($model['live'] && $model['live']->hasRoute($routeID)) {
                return $model;
            }
            if ($model['static'] && $model['static']->hasRoute($routeID)) {
                return $model;
            }
        }
        return array('system' => $system, 'live' => false, 'static' => false);
    }
    
    protected function modelsForStop($system, $stopID) {
        $models = array();
      
        foreach ($this->models as $model) {
            if ($model['system'] != $system) { continue; }
        
            if (($model['live'] && $model['live']->hasStop($stopID)) ||
                ($model['static'] && $model['static']->hasStop($stopID))) {
                $models[] = $model;
            }
        }
        return $models;
    }
    
    protected function getGlobalID($system, $realID) {
        return $system.$this->globalIDSeparator.$realID;
    }
    
    protected function getRealID($globalID) {
        $parts = explode($this->globalIDSeparator, $globalID);
        if (count($parts) == 2) {
            return $parts;
        } else {
            throw new Exception("Invalid global view ID '$globalID'");
        }
    }
}

class TransitConfig
{
    protected $models = array();
    
    function __construct($feedConfigs) {
        foreach ($feedConfigs as $id => $config) {
            $system = isset($config['system']) ? $config['system'] : $id;
            
            // Figure out model classes
            $liveModelClass = null;
            if (isset($config['live_class']) && $config['live_class']) {
                $liveModelClass = $config['live_class'];
            }
            unset($config['live_class']);
      
            $staticModelClass = null;
            if (isset($config['static_class']) && $config['static_class']) {
                $staticModelClass = $config['static_class'];
            }
            unset($config['static_class']);
            
            // Add models
            if (isset($liveModelClass) || isset($staticModelClass)) {
                $this->models[$id] = array(
                    'system' => $system,
                );
            }
            if (isset($liveModelClass) && $liveModelClass) {
                $this->models[$id]['live'] = array(
                    'class'     => $liveModelClass,
                    'arguments' => array(),
                    'overrides' => array(),
                );
            }
            if (isset($staticModelClass) && $staticModelClass) {
                $this->models[$id]['static'] = array(
                    'class'     => $staticModelClass,
                    'arguments' => array(),
                    'overrides' => array(),
                );
            }
            
            // Read overrides and arguments
            foreach ($config as $configKey => $configValue) {
                $parts = explode('_', $configKey);
                
                if (count($parts) < 3) { continue; } // skip extra keys
                
                $model = $parts[0];
                $type = $parts[1];
                $keyOrVal = end($parts);
                
                if (!($model == 'live' || $model == 'static' || $model == 'all')) {
                    Kurogo::log(LOG_WARNING, "unknown transit configuration type '$type'", 'transit');
                    continue;
                }
                $models = ($model == 'all') ? array('live', 'static') : array($model);
                
                // skip values so we don't add twice
                if ($keyOrVal !== 'keys') { continue; }
                
                $configValueKey = implode('_', array_slice($parts, 0, -1)).'_vals';
                if (!isset($config[$configValueKey])) {
                    Kurogo::log(LOG_WARNING, "transit configuration file missing value '$configValueKey' for key '$configKey'", 'transit');
                    continue;
                }
                
                $fieldKeys = $configValue;
                $fieldValues = $config[$configValueKey];
                
                switch ($type) {
                    case 'argument': 
                        foreach ($fieldKeys as $i => $fieldKey) {
                            $this->setArgument($id, $models, $fieldKey, $fieldValues[$i]);
                        }
                        break;
                      
                    case 'override':
                        if (count($parts) == 5) {
                            $object = $parts[2];
                            $field = $parts[3];
                            
                            foreach ($fieldKeys as $i => $fieldKey) {
                                $this->setFieldOverride($id, $models, $object, $field, $fieldKey, $fieldValues[$i]);
                            }
                        }
                        break;
                    
                    default:
                        Kurogo::log(LOG_WARNING, "unknown transit configuration key '$configKey'", 'transit');
                        break;
                }
            }
        }
    }
    
    protected function setArgument($id, $models, $key, $value) {
        foreach ($models as $model) {
            if (isset($this->models[$id], $this->models[$id][$model])) {
                $this->models[$id][$model]['arguments'][$key] = $value;
            }
        }
    }
    
    protected function setFieldOverride($id, $models, $object, $field, $key, $value) {
        foreach ($models as $model) {
            if (isset($this->models[$id], $this->models[$id][$model])) {
                if (!isset($this->models[$id][$model]['overrides'][$object])) {
                    $this->models[$id][$model]['overrides'][$object] = array();
                }
                if (!isset($this->models[$id][$model]['overrides'][$object][$field])) {
                    $this->models[$id][$model]['overrides'][$object][$field] = array();
                }
                $this->models[$id][$model]['overrides'][$object][$field][$key] = $value;
            }
        }
    }
    
    //
    // Query
    //
    
    protected function getModelValueForKey($id, $type, $key, $default) {
        if (isset($this->models[$id], 
                  $this->models[$id][$type], 
                  $this->models[$id][$type][$key])) {
                  
            return $this->models[$id][$type][$key];
        } else {
            return $default;
        }    
    }
    
    //
    // Public functions
    //
    
    public function getModelIDs() {
        return array_keys($this->models);
    }
    
    public function hasLiveModel($id) {
        return isset($this->models[$id], $this->models[$id]['live']);
    }
    public function hasStaticModel($id) {
        return isset($this->models[$id], $this->models[$id]['static']);
    }
    
    public function getSystem($id) {
        return isset($this->models[$id]) ? $this->models[$id]['system'] : $id;
    }
    
    public function getLiveModelClass($id) {
        return $this->getModelValueForKey($id, 'live', 'class', false);
    }
    public function getStaticModelClass($id) {
        return $this->getModelValueForKey($id, 'static', 'class', false);
    }
    
    public function getLiveModelArgs($id) {
        return $this->getModelValueForKey($id, 'live', 'arguments', array());
    }
    public function getStaticModelArgs($id) {
        return $this->getModelValueForKey($id, 'static', 'arguments', array());
    }
    
    public function getLiveModelOverrides($id) {
        return $this->getModelValueForKey($id, 'live', 'overrides', array());
    }
    public function getStaticModelOverrides($id) {
        return $this->getModelValueForKey($id, 'static', 'overrides', array());
    }
}