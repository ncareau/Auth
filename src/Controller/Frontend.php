<?php

namespace Bolt\Extension\Bolt\Members\Controller;

use Bolt\Extension\Bolt\Members\AccessControl\Session;
use Bolt\Extension\Bolt\Members\Config\Config;
use Bolt\Extension\Bolt\Members\Exception\InvalidAuthorisationRequestException;
use Bolt\Extension\Bolt\Members\Form;
use Bolt\Extension\Bolt\Members\Oauth2\Client\Provider;
use Bolt\Extension\Bolt\Members\Storage;
use Silex\Application;
use Silex\ControllerCollection;
use Silex\ControllerProviderInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Frontend controller.
 *
 * Copyright (C) 2014-2016 Gawain Lynch
 *
 * @author    Gawain Lynch <gawain.lynch@gmail.com>
 * @copyright Copyright (c) 2014-2016, Gawain Lynch
 * @license   https://opensource.org/licenses/MIT MIT
 */
class Frontend implements ControllerProviderInterface
{
    /** @var Config */
    private $config;

    /**
     * Constructor.
     *
     * @param Config $config
     */
    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * {@inheritdoc}
     */
    public function connect(Application $app)
    {
        /** @var $ctr ControllerCollection */
        $ctr = $app['controllers_factory'];

        $ctr->match('/profile/edit', [$this, 'editProfile'])
            ->bind('membersProfileEdit')
            ->method('GET|POST')
        ;

        $ctr->match('/profile/register', [$this, 'registerProfile'])
            ->bind('membersProfileRegister')
            ->method('GET|POST')
        ;

        $ctr->match('/profile/verify', [$this, 'verifyProfile'])
            ->bind('membersProfileVerify')
            ->method('GET')
        ;

        // Own the rest of the base route
        $ctr->match('/', [$this, 'defaultRoute'])
            ->bind('membersDefaultBase')
        ;

        $ctr->match('/{url}', [$this, 'defaultRoute'])
            ->bind('membersDefault')
            ->assert('url', '.+')
        ;

        $ctr->after([$this, 'after']);

        return $ctr;
    }

    /**
     * Middleware to modify the Response before it is sent to the client.
     *
     * @param Request     $request
     * @param Response    $response
     * @param Application $app
     */
    public function after(Request $request, Response $response, Application $app)
    {
        /** @var Session $session */
        $session = $app['members.session'];
        if ($session->getAuthorisation() === null) {
            $response->headers->clearCookie(Session::COOKIE_AUTHORISATION);

            return;
        }

        $cookie = $session->getAuthorisation()->getCookie();
        if ($cookie === null) {
            $response->headers->clearCookie(Session::COOKIE_AUTHORISATION);
        } else {
            $response->headers->setCookie(new Cookie(Session::COOKIE_AUTHORISATION, $cookie, 86400));
        }

        $request->attributes->set('members-cookies', 'set');
    }

    /**
     * Default catch-all route.
     *
     * @param Application $app
     * @param Request     $request
     *
     * @return RedirectResponse
     */
    public function defaultRoute(Application $app, Request $request)
    {
        if ($app['members.session']->hasAuthorisation()) {
            return new RedirectResponse($app['url_generator']->generate('membersProfileEdit'));
        }

        return new RedirectResponse($app['url_generator']->generate('authenticationLogin'));
    }

    /**
     * Edit an existing member profile.
     *
     * @param Application $app
     * @param Request     $request
     *
     * @return Response
     */
    public function editProfile(Application $app, Request $request)
    {
        /** @var Session $session */
        $session = $app['members.session'];
        /** @var Form\Manager $formsManager */
        $formsManager = $app['members.forms.manager'];
        /** @var Form\Type\ProfileEditType $profileFormType */
        $profileFormType = $app['members.form.components']['type']['profile_edit'];

        $memberSession = $session->getAuthorisation();

        if ($memberSession === null) {
            $app['session']->set(Authentication::FINAL_REDIRECT_KEY, $request->getUri());
            $app['members.feedback']->info('Login required to edit your profile');

            return new RedirectResponse($app['url_generator']->generate('authenticationLogin'));
        }

        $profileFormType->setRequirePassword(false);
        $resolvedForm = $formsManager->getFormProfileEdit($request, true);

        // Handle the form request data
        if ($resolvedForm->getForm('form_profile')->isValid()) {
            /** @var Form\ProfileEditForm $profileForm */
            $profileForm = $app['members.form.profile_edit'];
            $profileForm->saveForm($app['members.records'], $app['dispatcher']);
        }

        $template = $this->config->getTemplates('profile', 'edit');
        $html = $formsManager->renderForms($resolvedForm, $template);

        return new Response(new \Twig_Markup($html, 'UTF-8'));
    }

    /**
     * Register a new member profile.
     *
     * @param Application $app
     * @param Request     $request
     *
     * @return Response
     */
    public function registerProfile(Application $app, Request $request)
    {
        /** @var Form\Manager $formsManager */
        $formsManager = $app['members.forms.manager'];
        $resolvedForm = $formsManager->getFormProfileRegister($request, true);

        // Handle the form request data
        if ($resolvedForm->getForm('form_register')->isValid()) {
            $app['members.oauth.provider.manager']->setProvider($app, 'local');
            /** @var Form\ProfileRegisterForm $registerForm */
            $registerForm = $app['members.form.profile_register'];
            $registerForm
                ->setProvider($app['members.oauth.provider'])
                ->saveForm($app['members.records'], $app['dispatcher'])
            ;
            // Redirect to our profile page.
            $response =  new RedirectResponse($app['url_generator']->generate('membersProfileEdit'));

            return $response;
        }

        $context = ['transitional' => $app['members.session']->isTransitional()];
        $template = $this->config->getTemplates('profile', 'register');
        $html = $formsManager->renderForms($resolvedForm, $template, $context);

        return new Response(new \Twig_Markup($html, 'UTF-8'));
    }

    /**
     * Handles the user profile verification call.
     *
     * @param Application $app
     * @param Request     $request
     *
     * @return Response
     */
    public function verifyProfile(Application $app, Request $request)
    {
        // Get the verification code
        $code = $request->query->get('code');
        if (strlen($code) !== 40) {
            throw new InvalidAuthorisationRequestException('Invalid code');
        }

        // Get the verification key meta entity
        $metaEntities = $app['members.records']->getAccountMetaValues('account-verification-key', $code);
        if ($metaEntities === false) {
            throw new InvalidAuthorisationRequestException('No meta code');
        }
        /** @var Storage\Entity\AccountMeta $metaEntity */
        $metaEntity = reset($metaEntities);
        $guid = $metaEntity->getGuid();

        // Get the account and set it as verified
        $account = $app['members.records']->getAccountByGuid($guid);
        if ($account === false) {
            throw new InvalidAuthorisationRequestException('No account');
        }
        $account->setVerified(true);
        $app['members.records']->saveAccount($account);

        // Remove meta record
        $app['members.records']->deleteAccountMeta($metaEntity);

        return 'Success';
    }
}
