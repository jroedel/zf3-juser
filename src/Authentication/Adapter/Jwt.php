<?php

namespace JUser\Authentication\Adapter;

use Interop\Container\ContainerInterface;
use Zend\Authentication\Result as AuthenticationResult;
use Zend\EventManager\EventInterface;
use Zend\ServiceManager\ServiceManager;
// use Zend\Session\Container as SessionContainer;
use ZfcUser\Mapper\UserInterface as UserMapperInterface;
use ZfcUser\Options\ModuleOptions;
use Zend\Authentication\Adapter\AdapterInterface;
use Zend\Authentication\Result;

/**
 * The plan here is to copy over code from ApiController to check the validity of the JWT. 
 * 
 * Then we simply accept the sub parameter of the jwt payload as the current userId.  We probably 
 * have to query the database for the whole userObject
 * 
 * It might be better to extend the Db class directly instead of trying to slim it down.
 * @author user
 *
 */
class Jwt implements AdapterInterface
{
    /**
     * @var UserMapperInterface
     */
    protected $mapper;

    /**
     * @var callable
     */
    protected $credentialPreprocessor;

    /**
     * @var ServiceManager
     */
    protected $serviceManager;

    /**
     * @var ModuleOptions
     */
    protected $options;

    /**
     * Called when user id logged out
     * @param EventInterface $e
     */
//     public function logout(EventInterface $e)
//     {
//         $this->getStorage()->clear();
//     }

    /**
     * @param EventInterface $e
     * @return bool
     */
    public function authenticate()
    {
        //check if we've already authenticated the user
//         $e = $e->getTarget();
//         if ($this->isSatisfied()) {
//             $storage = $this->getStorage()->read();
//             $e->setIdentity($storage['identity'])
//               ->setCode(AuthenticationResult::SUCCESS)
//               ->setMessages(array('Authentication successful.'));
//             return;
//         }
        
        $request = $this->getServiceManager()->get('Request');
//         $response = $event->getResponse();
//         $isAuthorizationRequired = $event->getRouteMatch()->getParam('isAuthorizationRequired');
        $config = $this->getServiceManager()->get('Config');
        
        if (isset($config['ApiRequest']) && isset($config['ApiRequest']['jwtAuth'])
            && isset($config['ApiRequest']['jwtAuth']['cypherKey']) 
            && isset($config['ApiRequest']['jwtAuth']['tokenAlgorithm'])
        ) {
            $cypherKey = $config['ApiRequest']['jwtAuth']['cypherKey'];
            $tokenAlgorithm = $config['ApiRequest']['jwtAuth']['tokenAlgorithm'];
            $jwtToken = $this->findJwtToken($request);
            if ($jwtToken) {
                $payload = $this->decodeJwtToken($jwtToken, $cypherKey, $tokenAlgorithm);
                if (is_object($payload)) {
                    //now we have a validly signed JWT
                    if (! property_exists($payload, 'sub')) {
                        //failure, all JWTs should contain a sub with the userId
                        return new Result(
                            AuthenticationResult::FAILURE_IDENTITY_AMBIGUOUS, 
                            [], 
                            ['No identity supplied.']
                            );
                    }
                    $userId = $payload->sub;
                    $userObject = $this->getMapper()->findById($userId);
                    
                    if (!$userObject) {
                        return new Result(
                            AuthenticationResult::FAILURE_IDENTITY_NOT_FOUND, 
                            [], 
                            ['A record with the supplied identity could not be found.']
                            );
                    }
                    //validate that the user specified is activated
                    
                    if ($this->getOptions()->getEnableUserState()) {
                        // Don't allow user to login if state is not in allowed list
                        if (!in_array($userObject->getState(), $this->getOptions()->getAllowedLoginStates())) {
                            return new Result(
                                AuthenticationResult::FAILURE_UNCATEGORIZED,
                                [],
                                ['A record with the supplied identity is not active.']
                                );
                        }
                    }
                    //success, we found a validly signed JWT
                    
                    // regen the id
//                     $session = new SessionContainer($this->getStorage()->getNameSpace());
//                     $session->getManager()->regenerateId();
                    
                    // Success!
//                     $storage = $this->getStorage()->read();
//                     $storage['identity'] = $e->getIdentity();
//                     $this->getStorage()->write($storage);
                    return new Result(
                        AuthenticationResult::SUCCESS,
                        $userObject,
                        ['Authentication successful.']
                        );
                }
                //failure, invalid JWT or signature
                return new Result(
                    AuthenticationResult::FAILURE_CREDENTIAL_INVALID,
                    [],
                    ['Supplied credential is invalid.']
                    );
            } else {
                //failure, we couldn't find the JWT.  it probably wasn't included
                return new Result(
                    AuthenticationResult::FAILURE_UNCATEGORIZED,
                    [],
                    ['No valid JWT found.']
                    );
            }
        } else {
            //failure, the application isn't configured to receive JWT's. We should throw an exception
            throw \Zend\Authentication\Adapter\Exception\ExceptionInterface(
                    "Please disable JWT auth or configure ['ApiRequest']['jwtAuth']"
                );
        }
    }
    
    /**
     * Check Request object have Authorization token or not
     * @param \Zend\Stdlib\RequestInterface $request
     * @return string
     */
    public function findJwtToken($request)
    {
        $jwtToken = $request->getHeaders("Authorization") ? $request->getHeaders("Authorization")->getFieldValue() : '';
        if ($jwtToken) {
            $jwtToken = trim(trim($jwtToken, "Bearer"), " ");
            return $jwtToken;
        }
        if ($request->isGet()) {
            $jwtToken = $request->getQuery('token');
        }
        if ($request->isPost()) {
            $jwtToken = $request->getPost('token');
        }
        return $jwtToken;
    }
    
    /**
     * contain encoded token for user.
     */
    protected function decodeJwtToken($token, $cypherKey, $tokenAlgorithm)
    {
        try {
            $decodeToken = \Firebase\JWT\JWT::decode($token, $cypherKey, [$tokenAlgorithm]);
        } catch (\Exception $e) {
            //@todo log
            return null;
        }
        return $decodeToken;
    }
    
    /**
     * getMapper
     *
     * @return UserMapperInterface
     */
    public function getMapper()
    {
        if (null === $this->mapper) {
            $this->mapper = $this->getServiceManager()->get('zfcuser_user_mapper');
        }

        return $this->mapper;
    }

    /**
     * setMapper
     *
     * @param UserMapperInterface $mapper
     * @return Jwt
     */
    public function setMapper(UserMapperInterface $mapper)
    {
        $this->mapper = $mapper;

        return $this;
    }

    /**
     * Retrieve service manager instance
     *
     * @return ServiceManager
     */
    public function getServiceManager()
    {
        return $this->serviceManager;
    }

    /**
     * Set service manager instance
     *
     * @param ContainerInterface $serviceManager
     */
    public function setServiceManager(ContainerInterface $serviceManager)
    {
        $this->serviceManager = $serviceManager;
    }

    /**
     * @param ModuleOptions $options
     */
    public function setOptions(ModuleOptions $options)
    {
        $this->options = $options;
    }

    /**
     * @return ModuleOptions
     */
    public function getOptions()
    {
        if ($this->options === null) {
            $this->setOptions($this->getServiceManager()->get('zfcuser_module_options'));
        }

        return $this->options;
    }
}
