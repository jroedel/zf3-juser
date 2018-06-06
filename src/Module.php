<?php
namespace JUser;

use Zend\Mvc\MvcEvent;
use Zend\Db\TableGateway\Feature\GlobalAdapterFeature;
class Module
{
    public function getConfig()
    {
        return include __DIR__ . '/../config/module.config.php';
    }
    
    public function onBootstrap(MvcEvent $e)
    {
        //The static adapter is needed for the EditUserForm
        $sm = $e->getApplication()->getServiceManager();
        $config = $sm->get('Config');
        if (isset($config['zfcuser']['zend_db_adapter']) &&
            $sm->has($config['zfcuser']['zend_db_adapter'])) {
                $adapter = $sm->get($config['zfcuser']['zend_db_adapter']);
                GlobalAdapterFeature::setStaticAdapter($adapter);
            } else {
                throw new \Exception('Please set the [\'zfcuser\'][\'zend_db_adapter\'] config key for use with the JUser module.');
            }
    }
}