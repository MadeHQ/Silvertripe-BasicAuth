<?php

namespace Mademedia\SilverstripeBasicauth;

use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Extensible;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\CompositeField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataExtension;
use SilverStripe\ORM\Hierarchy\Hierarchy;
use UncleCheese\DisplayLogic\Forms\Wrapper;

/**
 *
 */
class DataObjectExtension extends DataExtension
{
    public static function get_extra_config($class, $extension, $args)
    {
        $config = [];
        if (Extensible::has_extension($class, Hierarchy::class)) {
            $config['db'] = [
                'BasicAuthInherit' => 'Boolean(1)',
            ];
            $config['defaults'] = [
                'BasicAuthInherit' => '1',
            ];
        }
        return $config;
    }

    /**
     * {@inheritdoc}
     */
    private static $db = [
        // 'BasicAuthInherit' => 'Boolean(1)', // Applied in `::get_extra_config()` but only if `Hierarchy` is applied, added here for reference
        'BasicAuthUsername' => 'Varchar(150)',
        'BasicAuthPassword' => 'Varchar(150)',
    ];

    /**
     * {@inheritdoc}
     */
    private static $defaults = [
        // 'BasicAuthInherit' => '1',   // Applied in `::get_extra_config()` but only if `Hierarchy` is applied, added here for reference
    ];

    public function updateCMSFields(FieldList $fields)
    {
        // If not being applied to `SiteTree` then just add the fields
        if (!method_exists($this->owner, 'getSettingsFields')) {
            $this->updateSettingsFields($fields);
        }
    }

    public function updateSettingsFields(FieldList $fields)
    {
        // If the page is top level then don't show the `BasicAuthInherit` field (also means don't want to use DisplayLogic)
        if ($this->owner->ParentID > 0) {
            $basicAuthFields = [
                CheckboxField::create('BasicAuthInherit', _t(__CLASS__ . '.INHERIT_FROM_PARENT', 'Inherit from parent')),
            ];
            if (class_exists(Wrapper::class)) {
                $basicAuthFields[] = Wrapper::create(
                    TextField::create('BasicAuthUsername', _t(__CLASS__ . '.USERNAME', 'Username')),
                    TextField::create('BasicAuthPassword', _t(__CLASS__ . '.PASSWORD', 'Password'))
                )->displayIf('BasicAuthInherit')->isNotChecked()->end();
            } else {
                $basicAuthFields[] = CompositeField::create(
                    TextField::create('BasicAuthUsername', _t(__CLASS__ . '.USERNAME', 'Username')),
                    TextField::create('BasicAuthPassword', _t(__CLASS__ . '.PASSWORD', 'Password'))
                )->displayIf('BasicAuthInherit')->isNotChecked()->end();
            }
        } else {
            $basicAuthFields = [
                CompositeField::create(
                    TextField::create('BasicAuthUsername', _t(__CLASS__ . '.USERNAME', 'Username')),
                    TextField::create('BasicAuthPassword', _t(__CLASS__ . '.PASSWORD', 'Password'))
                )
            ];
        }

        $fields->addFieldsToTab(
            'Root.Settings',
            CompositeField::create($basicAuthFields)->setTitle(_t(__CLASS__ . '.BASIC_AUTH', 'Basic Auth'))
        );
    }

    public function doVerifyBasicAuth(HTTPRequest $request)
    {
        if (!(list($username, $password, $realmName, $realmURL) = $this->getBasicAuthDetails())) {
            return;
        }

        try {
            if (!$request->getHeader('php_auth_user')) {
                $this->httpError($request, 401, 'Login required');
            }
            if ($request->getHeader('php_auth_user') !== $username) {
                $this->httpError($request, 401, 'Failed Login');
            }
            if ($request->getHeader('php_auth_pw') !== $password) {
                $this->httpError($request, 401, 'Failed Login');
            }
        } catch (HTTPResponse_Exception $e) {
            // Catches the exception to add the `WWW-Authenticate` header
            $realm = sprintf('Basic realm="ACT SF: %s (%s)"', $realmName, $realmURL);
            $e->getResponse()->addHeader('WWW-Authenticate', $realm);
            throw $e;
        }
    }

    /**
     * If not applied to SiteTree then this is just a precaution to prevent errors and keep consistent Realm data
     */
    public function RelativeLink()
    {
        return sprintf('item-%s-%d', strtolower($this->owner->ClassName), $this->owner->ID);
    }

    /**
     * If the page is protected by BasicAuth (either inherited or explicit) then will return an array of data with `Username`, `Password`, `MenuTitle` & `RelativeLink()`
     *   (everything that is required for BasicAuth authentication and Realm header)
     *
     * @uses Hierarchy::Parent()
     *
     * @return Mixed (false|array())
     */
    public function getBasicAuthDetails()
    {
        // If `Hierarchy` extension not applied the `::BasicAuthInherit` will be null (falsey) anyway
        if ($this->owner->BasicAuthInherit && $this->owner->ParentID > 0 && ($parent = $this->owner->Parent()) && $parent->exists()) {
            return $parent->getBasicAuthDetails();
        }
        if ($this->owner->BasicAuthUsername) {
            return [$this->owner->BasicAuthUsername, $this->owner->BasicAuthPassword, $this->owner->Title ?: $this->owner->Name, $this->owner->RelativeLink()];
        }
        return false;
    }

    /**
     * Copied from `RequestHandler::httpError()`
     *
     * @param int $errorCode
     * @param string $errorMessage Plaintext error message
     * @uses HTTPResponse_Exception
     * @throws HTTPResponse_Exception
     */
    private function httpError($request, $errorCode, $errorMessage = null)
    {
        // Call a handler method such as onBeforeHTTPError404
        $this->owner->extend("onBeforeHTTPError{$errorCode}", $request, $errorMessage);

        // Call a handler method such as onBeforeHTTPError, passing 404 as the first arg
        $this->owner->extend('onBeforeHTTPError', $errorCode, $request, $errorMessage);

        // Throw a new exception
        throw new HTTPResponse_Exception($errorMessage, $errorCode);
    }
}