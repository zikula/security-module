<?php
/**
 * Copyright Zikula Foundation 2009 - Zikula Application Framework
 *
 * This work is contributed to the Zikula Foundation under one or more
 * Contributor Agreements and licensed to You under the following license:
 *
 * @license GNU/LGPLv3 (or at your option, any later version).
 *
 * Please see the NOTICE file distributed with this source code for further
 * information regarding copyright and licensing.
 */

namespace Zikula\Module\AdminModule\Controller;

use Zikula_View;
use ModUtil;
use SecurityUtil;
use System;
use DataUtil;
use StringUtil;
use Zikula_Core;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route; // used in annotations - do not remove
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method; // used in annotations - do not remove
use Symfony\Component\Routing\RouterInterface;

/**
 * NOTE: intentionally no class level route setting here
 *
 * Administrative controllers for the admin module
 */
class AdminController extends \Zikula_AbstractController
{
    /**
     * Post initialise.
     *
     * @return void
     */
    protected function postInitialize()
    {
        // In this controller we do not want caching.
        $this->view->setCaching(Zikula_View::CACHE_DISABLED);
    }

    /**
     * @Route("")
     *
     * the main administration function
     *
     * This function is the default function, and is called whenever the
     * module is initiated without defining arguments.  As such it can
     * be used for a number of things, but most commonly it either just
     * shows the module menu and returns or calls whatever the module
     * designer feels should be the default function (often this is the
     * view() function)
     *
     * @return RedirectResponse symfony response object
     */
    public function indexAction()
    {
        // Security check will be done in view()
        return new RedirectResponse($this->get('router')->generate('zikulaadminmodule_admin_view', array(), RouterInterface::ABSOLUTE_URL));
    }

    /**
     * @Route("/categories/{startnum}", requirements={"startnum" = "\d+"})
     * @Method("GET")
     *
     * View all admin categories
     *
     * @param integer $startnum
     *
     * @return Response symfony response object
     *
     * @throws AccessDeniedException Thrown if the user doesn't have edit permission to the module
     */
    public function viewAction($startnum = 0)
    {
        if (!SecurityUtil::checkPermission('ZikulaAdminModule::', '::', ACCESS_EDIT)) {
            throw new AccessDeniedException();
        }

        $itemsperpage = $this->getVar('itemsperpage');

        $categories = array();
        $items = ModUtil::apiFunc('ZikulaAdminModule', 'admin', 'getall',
                                          array('startnum' => $startnum,
                                                'numitems' => $itemsperpage));
        foreach ($items as $item) {
            if (SecurityUtil::checkPermission('ZikulaAdminModule::', "$item[name]::$item[cid]", ACCESS_READ)) {
                $categories[] = $item;
            }
        }
        $this->view->assign('categories', $categories);

        $numitems = ModUtil::apiFunc('ZikulaAdminModule', 'admin', 'countitems');
        $this->view->assign('pager', array('numitems' => $numitems,
                                           'itemsperpage' => $itemsperpage));

        // Return the output that has been generated by this function
        return new Response($this->view->fetch('Admin/view.tpl'));
    }

    /**
     * @Route("/newcategory")
     * @Method("GET")
     *
     * Display a new admin category form
     *
     * Displays a form for the user to input the details of the new category. Data is supplied to @see this::createAction()
     *
     * @return Response symfony response object
     *
     * @throws AccessDeniedException Thrown if the user doesn't have permission to add a category
     */
    public function newcatAction()
    {
        if (!SecurityUtil::checkPermission('ZikulaAdminModule::Item', '::', ACCESS_ADD)) {
            throw new AccessDeniedException();
        }

        // Return the output that has been generated by this function
        return new Response($this->view->fetch('Admin/newcat.tpl'));
    }

    /**
     * @Route("/newcategory")
     * @Method("POST")
     *
     * Create a new admin category
     *
     * This function processes the user input from the form in @see this::newcatAction()
     *
     * @param Request $request
     *
     * @return RedirectResponse
     *
     * @throws AccessDeniedException Thrown if the user doesn't have permission to add the category
     */
    public function createAction(Request $request)
    {
        $this->checkCsrfToken();

        $category = $request->request->get('category', null);

        // Security check
        if (!SecurityUtil::checkPermission('ZikulaAdminModule::Category', "$category[name]::", ACCESS_ADD)) {
            throw new AccessDeniedException();
        }

        $cid = ModUtil::apiFunc('ZikulaAdminModule', 'admin', 'create',
                    array('name' => $category['name'],
                          'description' => $category['description']));

        if (is_numeric($cid)) {
            $request->getSession()->getFlashBag()->add('status', $this->__('Done! Created new category.'));
        }

        return new RedirectResponse($this->get('router')->generate('zikulaadminmodule_admin_view', array(), RouterInterface::ABSOLUTE_URL));
    }

    /**
     * @Route("/modifycategory/{cid}", requirements={"cid" = "^[1-9]\d*$"})
     * @Method("GET")
     *
     * Displays a modify category form
     *
     * Displays a form for the user to edit the details of a category. Data is supplied to @see this::updateAction()
     *
     * @param integer $cid
     *
     * @return Response symfony response object
     *
     * @throws AccessDeniedException Thrown if the user doesn't have permission to edit the category
     * @throws NotFoundHttpException Thrown if the requested category cannot be found
     */
    public function modifyAction($cid)
    {
        $category = ModUtil::apiFunc('ZikulaAdminModule', 'admin', 'getCategory', array('cid' => $cid));
        if (empty($category)) {
            throw new NotFoundHttpException($this->__('Error! No such category found.'));
        }

        if (!SecurityUtil::checkPermission('ZikulaAdminModule::Category', "$category[name]::$cid", ACCESS_EDIT)) {
            throw new AccessDeniedException();
        }

        $this->view->assign('category', $category);

        return new Response($this->view->fetch('Admin/modify.tpl'));
    }

    /**
     * @Route("/modifycategory")
     * @Method("POST")
     *
     * Update an admin category
     *
     * This function processes the user input from the form in @see this::modifyAction()
     *
     * @param Request $request
     *
     * @return RedirectResponse
     *
     * @throws AccessDeniedException Thrown if the user doesn't have edit permission over the category
     */
    public function updateAction(Request $request)
    {
        $this->checkCsrfToken();

        $category = $request->request->get('category', null);

        if (!SecurityUtil::checkPermission('ZikulaAdminModule::Category', "$category[name]:$category[cid]", ACCESS_EDIT)) {
            throw new AccessDeniedException();
        }

        $update = ModUtil::apiFunc('ZikulaAdminModule', 'admin', 'update',
                    array('cid' => $category['cid'],
                          'name' => $category['name'],
                          'description' => $category['description']));

        if ($update) {
            // Success
            $request->getSession()->getFlashBag()->add('status', $this->__('Done! Saved category.'));
        }

        return new RedirectResponse($this->get('router')->generate('zikulaadminmodule_admin_view', array(), RouterInterface::ABSOLUTE_URL));
    }

    /**
     * @Route("/deletecategory")
     *
     * delete item
     *
     * This is a standard function that is called whenever an administrator
     * wishes to delete a current module item.
     *
     * @return Response Symfony response object if confirmation is null
     *
     * @throws AccessDeniedException Thrown if the user doesn't have permission to delete the category
     * @throws NotFoundHttpException Thrown if the category cannot be found
     */
    public function deleteAction(Request $request)
    {
        // check where to get the parameters from for this dual purpose controller
        if ($request->isMethod('GET')) {
            $cid = (int)$request->query->get('cid', null);
        } elseif ($request->isMethod('POST')) {
            $cid = (int)$request->request->get('cid', null);
        }

        // confirmation can only come from a form so use post only here
        $confirmation = $request->request->get('confirmation', null);

        $category = ModUtil::apiFunc('ZikulaAdminModule', 'admin', 'getCategory', array('cid' => $cid));
        if (empty($category)) {
            throw new NotFoundHttpException($this->__('Error! No such category found.'));
        }

        if (!SecurityUtil::checkPermission('ZikulaAdminModule::Category', "$category[name]::$cid", ACCESS_DELETE)) {
            throw new AccessDeniedException();
        }

        // Check for confirmation.
        if (empty($confirmation)) {
            // No confirmation yet - display a suitable form to obtain confirmation
            // of this action from the user
            return new Response($this->view->assign('category', $category)
                              ->fetch('Admin/delete.tpl'));
        }

        $this->checkCsrfToken();

        try {
            // delete category
            $delete = ModUtil::apiFunc('ZikulaAdminModule', 'admin', 'delete', array('cid' => $cid));
            // Success
            if ($delete) {
                $request->getSession()->getFlashBag()->add('status', $this->__('Done! Category deleted.'));
            }
        } catch (\RuntimeException $e) {
            $request->getSession()->getFlashBag()->add('error', $e->getMessage());
        }

        return new RedirectResponse($this->get('router')->generate('zikulaadminmodule_admin_view', array(), RouterInterface::ABSOLUTE_URL));
    }

    /**
     * @Route("/panel/{acid}", requirements={"acid" = "^[1-9]\d*$"})
     * @Method("GET")
     *
     * Display main admin panel for a category
     *
     * @param integer $acid
     *
     * @return Response symfony response object
     *
     * @throws AccessDeniedException Thrown if the user doesn't have edit permissions to the module
     */
    public function adminpanelAction($acid = null)
    {
        if (!SecurityUtil::checkPermission('::', '::', ACCESS_EDIT)) {
            // suppress admin display - return to index.
            if (!SecurityUtil::checkPermission('ZikulaAdminModule::', '::', ACCESS_EDIT)) {
                throw new AccessDeniedException();
            }
        }

        if (!$this->getVar('ignoreinstallercheck') && System::isDevelopmentMode()) {
            // check if the Zikula Recovery Console exists
            $zrcexists = file_exists('zrc.php');
            // check if upgrade scripts exist
            if ($zrcexists == true) {
                return new Response($this->view->assign('zrcexists', $zrcexists)
                                  ->assign('adminpanellink', $this->get('router')->generate('zikulaadminmodule_admin_adminpanel'))
                                  ->fetch('Admin/warning.tpl'));
            }
        }

        // Now prepare the display of the admin panel by getting the relevant info.

        // cid isn't set, so go to the default category
        if (empty($acid)) {
            $acid = $this->getVar('startcategory');
        }

        // Add category menu to output
        $this->view->assign('menu', $this->categorymenuAction($acid)->getContent());

        // Check to see if we have access to the requested category.
        if (!SecurityUtil::checkPermission('ZikulaAdminModule::', "::$acid", ACCESS_ADMIN)) {
            $acid = -1;
        }

        // Get Details on the selected category
        if ($acid > 0) {
            $category = ModUtil::apiFunc('ZikulaAdminModule', 'admin', 'getCategory', array('cid' => $acid));
        } else {
            $category = null;
        }

        if (!$category) {
            // get the default category
            $acid = $this->getVar('startcategory');

            // Check to see if we have access to the requested category.
            if (!SecurityUtil::checkPermission('ZikulaAdminModule::', "::$acid", ACCESS_ADMIN)) {
                throw new AccessDeniedException();
            }

            $category = ModUtil::apiFunc('ZikulaAdminModule', 'admin', 'getCategory', array('cid' => $acid));
        }

        // assign the category
        $this->view->assign('category', $category);

        $displayNameType = $this->getVar('displaynametype', 1);

        // get admin capable modules
        $adminmodules = ModUtil::getAdminMods();
        $adminlinks = array();
        foreach ($adminmodules as $adminmodule) {
            if (SecurityUtil::checkPermission("{$adminmodule['name']}::", 'ANY', ACCESS_EDIT)) {
                $catid = ModUtil::apiFunc('ZikulaAdminModule', 'admin', 'getmodcategory',
                        array('mid' => ModUtil::getIdFromName($adminmodule['name'])));
                $order = ModUtil::apiFunc('ZikulaAdminModule', 'admin', 'getSortOrder',
                        array('mid' => ModUtil::getIdFromName($adminmodule['name'])));
                if (($catid == $acid) || (($catid == false) && ($acid == $this->getVar('defaultcategory')))) {
                    $modinfo = ModUtil::getInfoFromName($adminmodule['name']);
                    $menutexturl = ModUtil::url($modinfo['name'], 'admin', 'index');

                    if ($displayNameType == 1) {
                        $menutext = $modinfo['displayname'];
                    } elseif ($displayNameType == 2) {
                        $menutext = $modinfo['name'];
                    } elseif ($displayNameType == 3) {
                        $menutext = $modinfo['displayname'] . ' (' . $modinfo['name'] . ')';
                    }
                    $menutexttitle = $modinfo['description'];

                    $adminicon = ModUtil::getModuleImagePath($adminmodule['name']);

                    $adminlinks[] = array('menutexturl' => $menutexturl,
                            'menutext' => $menutext,
                            'menutexttitle' => $menutexttitle,
                            'modname' => $modinfo['name'],
                            'adminicon' => $adminicon,
                            'id' => $modinfo['id'],
                            'order'=> $order);
                }
            }
        }
        usort($adminlinks, 'Zikula\Module\AdminModule\Controller\AdminController::_sortAdminModsByOrder');
        $this->view->assign('adminlinks', $adminlinks);

        return new Response($this->view->fetch('Admin/adminpanel.tpl'));
    }

    /**
     * @Route("/config")
     * @Method("GET")
     *
     * This is a standard function to modify the configuration parameters of the
     * module.
     *
     * @return Response symfony response object
     *
     * @throws AccessDeniedException Thrown if the user doesn't have admin permission to the module
     */
    public function modifyconfigAction()
    {
        if (!SecurityUtil::checkPermission('ZikulaAdminModule::', '::', ACCESS_ADMIN)) {
            throw new AccessDeniedException();
        }

        // get admin capable mods
        $adminmodules = ModUtil::getAdminMods();

        // Get all categories
        $categories = array();
        $items = ModUtil::apiFunc('ZikulaAdminModule', 'admin', 'getall');
        foreach ($items as $item) {
            if (SecurityUtil::checkPermission('ZikulaAdminModule::', "$item[name]::$item[cid]", ACCESS_READ)) {
                $categories[] = $item;
            }
        }
        $this->view->assign('categories', $categories);

        $modulecategories = array();
        foreach ($adminmodules as $adminmodule) {
            // Get the category assigned to this module
            $category = ModUtil::apiFunc('ZikulaAdminModule', 'admin', 'getmodcategory',
                    array('mid' => ModUtil::getIdFromName($adminmodule['name'])));

            if ($category === false) {
                // it's not set, so we use the default category
                $category = $this->getVar('defaultcategory');
            }
            // output module category selection
            $modulecategories[] = array('displayname' => $adminmodule['displayname'],
                    'name' => $adminmodule['name'],
                    'category' => $category);
        }

        $this->view->assign('modulecategories', $modulecategories);

        // Return the output that has been generated by this function
        return new Response($this->view->fetch('Admin/modifyconfig.tpl'));
    }

    /**
     * @Route("/config")
     * @Method("POST")
     *
     * This is a standard function to update the configuration parameters of the
     * module given the information passed back by the modification form.
     *
     * @param Request $request
     *  int    $modulesperrow  the number of modules to display per row in the admin panel
     *  int    $admingraphic   switch for display of admin icons
     *  int    $modulename,... the id of the category to set for each module
     *
     * @return RedirectResponse
     *
     * @throws AccessDeniedException Thrown if the user doesn't have admin permission to the module
     * @throws \RuntimeException Thrown if a module couldn't be added to the requested category
     */
    public function updateconfigAction(Request $request)
    {
        $this->checkCsrfToken();

        if (!SecurityUtil::checkPermission('ZikulaAdminModule::', '::', ACCESS_ADMIN)) {
            throw new AccessDeniedException();
        }

        // get module vars
        $modvars = $request->request->get('modvars', null, 'POST');

        // check module vars
        $modvars['modulesperrow'] = isset($modvars['modulesperrow']) ? $modvars['modulesperrow'] : 5;
        if (!is_numeric($modvars['modulesperrow'])) {
            $request->getSession()->getFlashBag()->add('error', $this->__("Error! You must enter a number for the 'Modules per row' setting."));
            return new RedirectResponse($this->get('router')->generate('zikulaadminmodule_admin_modifyconfig', array(), RouterInterface::ABSOLUTE_URL));
        }
        if (!is_numeric($modvars['itemsperpage'])) {
            $request->getSession()->getFlashBag()->add('error', $this->__("Error! You must enter a number for the 'Modules per page' setting."));
            return new RedirectResponse($this->get('router')->generate('zikulaadminmodule_admin_modifyconfig', array(), RouterInterface::ABSOLUTE_URL));
        }

        // set the module vars
        $modvars['ignoreinstallercheck'] = isset($modvars['ignoreinstallercheck']) ? $modvars['ignoreinstallercheck'] : false;
        $modvars['itemsperpage'] = isset($modvars['itemsperpage']) ? $modvars['itemsperpage'] : 5;
        $modvars['admingraphic'] = isset($modvars['admingraphic']) ? $modvars['admingraphic'] : 0;
        $modvars['displaynametype'] = isset($modvars['displaynametype']) ? $modvars['displaynametype'] : 1;
        $modvars['startcategory'] = isset($modvars['startcategory']) ? $modvars['startcategory'] : 1;
        $modvars['defaultcategory'] = isset($modvars['defaultcategory']) ? $modvars['defaultcategory'] : 1;
        $modvars['admintheme'] = isset($modvars['admintheme']) ? $modvars['admintheme'] : null;

        // save module vars
        ModUtil::setVars('ZikulaAdminModule', $modvars);

        // get admin modules
        $adminmodules = ModUtil::getModulesCapableOf('admin');
        $adminmods = $request->request->get('adminmods', null);

        foreach ($adminmodules as $adminmodule) {
            $category = $adminmods[$adminmodule['name']];

            if ($category) {
                // Add the module to the category
                $result = ModUtil::apiFunc('ZikulaAdminModule', 'admin', 'addmodtocategory',
                            array('module' => $adminmodule['name'],
                                  'category' => $category));

                if ($result == false) {
                    /** @var $cat \Zikula\Module\AdminModule\Entity\AdminCategoryEntity */
                    $cat = ModUtil::apiFunc($this->name, 'admin', 'getCategory', array('cid' => $category));
                    $request->getSession()->getFlashBag()->add('error', $this->__f('Error! Could not add module %1$s to module category %2$s.', array($adminmodule['name'], $cat->getName())));
                }
            }
        }

        // the module configuration has been updated successfuly
        $request->getSession()->getFlashBag()->add('status', $this->__('Done! Saved module configuration.'));

        // This function generated no output, and so now it is complete we redirect
        // the user to an appropriate page for them to carry on their work
        return new RedirectResponse($this->get('router')->generate('zikulaadminmodule_admin_view', array(), RouterInterface::ABSOLUTE_URL));
    }

    /**
     * @Route("/categorymenu/{acid}", requirements={"acid" = "^[1-9]\d*$"})
     * @Method("GET")
     *
     * Main category menu.
     *
     * @param integer $acid
     *
     * @return Response symfony response object
     */
    public function categorymenuAction($acid = null)
    {
        $acid = empty($acid) ? $this->getVar('startcategory') : $acid;

        // Get all categories
        $categories = array();
        $items = ModUtil::apiFunc('ZikulaAdminModule', 'admin', 'getall');
        foreach ($items as $item) {
            if (SecurityUtil::checkPermission('ZikulaAdminModule::', "$item[name]::$item[cid]", ACCESS_READ)) {
                $categories[] = $item;
            }
        }

        // get admin capable modules
        $adminmodules = ModUtil::getAdminMods();
        $adminlinks = array();

        foreach ($adminmodules as $adminmodule) {
            if (SecurityUtil::checkPermission("$adminmodule[name]::", '::', ACCESS_EDIT)) {
                $catid = ModUtil::apiFunc('ZikulaAdminModule', 'admin', 'getmodcategory', array('mid' => $adminmodule['id']));
                $order = ModUtil::apiFunc('ZikulaAdminModule', 'admin', 'getSortOrder',
                                          array('mid' => ModUtil::getIdFromName($adminmodule['name'])));
                $menutexturl = ModUtil::url($adminmodule['name'], 'admin', 'index');
                $menutext = $adminmodule['displayname'];
                $menutexttitle = $adminmodule['description'];
                $adminlinks[$catid][] = array('menutexturl' => $menutexturl,
                        'menutext' => $menutext,
                        'menutexttitle' => $menutexttitle,
                        'modname' => $adminmodule['name'],
                        'order' => $order,
                        'id' => $adminmodule['id'],
                        'icon' =>  ModUtil::getModuleImagePath($adminmodule['name'])
                );
            }
        }

        foreach ($adminlinks as &$item) {
            usort($item, 'Zikula\Module\AdminModule\Controller\AdminController::_sortAdminModsByOrder');
        }

        $menuoptions = array();
        $possible_cids = array();
        $permission = false;

        if (isset($categories) && is_array($categories)) {
            foreach ($categories as $category) {
                // only categories containing modules where the current user has permissions will
                // be shown, all others will be hidden
                // admin will see all categories
                if ( (isset($adminlinks[$category['cid']]) && count($adminlinks[$category['cid']]) )
                        || SecurityUtil::checkPermission('.*', '.*', ACCESS_ADMIN) ) {
                    $menuoption = array(
                        'url' => $this->get('router')->generate('zikulaadminmodule_admin_adminpanel', array('acid' => $category['cid'])),
                        'title' => $category['name'],
                        'description' => $category['description'],
                        'cid' => $category['cid']);
                    if (isset($adminlinks[$category['cid']])) {
                        $menuoption['items'] = $adminlinks[$category['cid']];
                    } else {
                        $menuoption['items'] = array();
                    }
                    $menuoptions[$category['cid']] = $menuoption;
                    $possible_cids[] = $category['cid'];

                    if ($acid == $category['cid']) {
                        $permission =true;
                    }
                }
            }
        }

        // if permission is false we are not allowed to see this category because its
        // empty and we are not admin
        if ($permission==false) {
            // show the first category
            $acid = !empty($possible_cids) ? (int)$possible_cids[0] : null;
        }

        $this->view->assign('currentcat', $acid);
        $this->view->assign('menuoptions', $menuoptions);

        // security analyzer and update checker warnings
        $notices = array();
        $notices['security'] = $this->_securityanalyzer();
        $notices['update'] = $this->_updatecheck();
        $notices['developer'] = $this->_developernotices();
        $this->view->assign('notices', $notices);

        return new Response($this->view->fetch('Admin/categorymenu.tpl'));
    }

    /**
     * @Route("/header")
     *
     * Open the admin container
     *
     * @return Response symfony response object
     */
    public function adminheaderAction()
    {
        return new Response($this->view->fetch('Admin/header.tpl'));
    }

    /**
     * @Route("/footer")
     *
     * Close the admin container
     *
     * @return Response symfony response object
     */
    public function adminfooterAction()
    {
        return new Response($this->view->fetch('Admin/footer.tpl'));
    }

    /**
     * @Route("/help")
     *
     * display the module help page
     *
     * @return Response symfony response object
     *
     * @throws AccessDeniedException Thrown if the user doesn't have admin permission to the module
     */
    public function helpAction()
    {
        if (!SecurityUtil::checkPermission('ZikulaAdminModule::', '::', ACCESS_ADMIN)) {
            throw new AccessDeniedException();
        }

        return new Response($this->view->fetch('Admin/help.tpl'));
    }

    /**
     * Get security analyzer data.
     *
     * @return array data
     */
    private function _securityanalyzer()
    {
        $data = array();

        // check for magic_quotes
        $data['magic_quotes_gpc'] = DataUtil::getBooleanIniValue('magic_quotes_gpc');

        // check for register_globals
        $data['register_globals'] = DataUtil::getBooleanIniValue('register_globals');

        // check for config.php being writable
        $data['config_php'] = (bool)is_writable('config/config.php');

        // check for .htaccess in temp directory
        $app_htaccess = false;
        $appDir = $this->getContainer()->get('kernel')->getRootDir();
        if ($appDir) {
            // check if we have an absolute path which is possibly not within the document root
            $docRoot = System::serverGetVar('DOCUMENT_ROOT');
            if (StringUtil::left($appDir, 1) == '/' && (strpos($appDir, $docRoot) === false)) {
                // temp dir is outside the webroot, no .htaccess file needed
                $app_htaccess = true;
            } else {
                if (strpos($appDir, $docRoot) === false) {
                    $ldir = dirname(__FILE__);
                    $p = strpos($ldir, DIRECTORY_SEPARATOR.'system'); // we are in system/Admin
                    $b = substr($ldir,0 , $p);
                    $filePath = $b.'/'.$appDir.'/.htaccess';
                } else {
                    $filePath = $appDir.'/.htaccess';
                }
                $app_htaccess = (bool) file_exists($filePath);
            }
        } else {
            // already customized, admin should know about what he's doing...
            $app_htaccess = true;
        }
        $data['app_htaccess'] = $app_htaccess;

        $data['scactive']  = (bool)ModUtil::available('ZikulaSecurityCenterModule');

        // check for outputfilter
        $data['useids'] = (bool)(ModUtil::available('ZikulaSecurityCenterModule') && System::getVar('useids') == 1);
        $data['idssoftblock'] = System::getVar('idssoftblock');

        return $data;
    }

    /**
     * Check for updates
     *
     * @param bool $force force an update check overriding time interval
     *
     * @return array|bool new version data or false
     */
    private function _updatecheck($force = false)
    {
        if (!System::getVar('updatecheck')) {
            return array('update_show' => false);
        }

        $now = time();
        $lastChecked = (int)System::getVar('updatelastchecked');
        $checkInterval = (int)System::getVar('updatefrequency') * 86400;
        $updateversion = System::getVar('updateversion');

        if ($force == false && (($now - $lastChecked) < $checkInterval)) {
            // dont get an update because TTL not expired yet
            $onlineVersion = $updateversion;
        } else {
            $onlineVersion = '';
            $newVersionInfo = trim($this->_zcurl('https://api.github.com/repos/zikula/core/releases'));
            if ($newVersionInfo === '') {
                return array('update_show' => false);
            }
            $newVersionInfo = json_decode($newVersionInfo, true);
            if (!is_array($newVersionInfo)) {
                return array('update_show' => false);
            }

            foreach ($newVersionInfo as $version) {
                if (!array_key_exists('prerelease', $version) || $version['prerelease']) {
                    continue;
                }
                if (array_key_exists('tag_name', $version)) {
                    if (version_compare($version['tag_name'], $onlineVersion) == 1) {
                        $onlineVersion = $version['tag_name'];
                    }
                }
            }

            if ($onlineVersion == '') {
                return array('update_show' => false);
            }

            System::setVar('updateversion', $onlineVersion);
            System::setVar('updatelastchecked', (int)time());
        }

        // if 1 then there is a later version available
        if (version_compare($onlineVersion, Zikula_Core::VERSION_NUM) == 1) {
            return array('update_show' => true,
                         'update_version' => $onlineVersion);
        } else {
            return array('update_show' => false);
        }
    }

    /**
     * Developer notices.
     *
     * @return array developer notice data or false
     */
    private function _developernotices()
    {
        $modvars = ModUtil::getVar('ZikulaThemeModule');

        $data = array();
        $data['devmode'] = $this->getContainer()->get('kernel')->getEnvironment() === 'dev';

        if ($data['devmode'] == true) {
            $data['cssjscombine'] = $modvars['cssjscombine'];

            if ($modvars['render_compile_check']) {
                $data['render']['compile_check'] = array('state' => $modvars['render_compile_check'],
                        'title' => $this->__('Compile check'));
            }
            if ($modvars['render_force_compile']) {
                $data['render']['force_compile'] = array('state' => $modvars['render_force_compile'],
                        'title' => $this->__('Force compile'));
            }
            if ($modvars['render_cache']) {
                $data['render']['cache']         = array('state' => $modvars['render_cache'],
                        'title' => $this->__('Caching'));
            }
            if ($modvars['compile_check']) {
                $data['theme']['compile_check']  = array('state' => $modvars['compile_check'],
                        'title' => $this->__('Compile check'));
            }
            if ($modvars['force_compile']) {
                $data['theme']['force_compile']  = array('state' => $modvars['force_compile'],
                        'title' => $this->__('Force compile'));
            }
            if ($modvars['enablecache']) {
                $data['theme']['cache']          = array('state' => $modvars['enablecache'],
                        'title' => $this->__('Caching'));
            }
        }

        return $data;
    }

    /**
     * Zikula curl
     *
     * This function is internal for the time being and may be extended to be a proper library
     * or find an alternative solution later.
     *
     * @param  string $url
     * @param  int    $timeout default=5
     *
     * @return string|bool false if no url handling functions are present or url string
     */
    private function _zcurl($url, $timeout=5)
    {
        $urlArray = parse_url($url);
        $data = '';
        $userAgent = 'Zikula/' . Zikula_Core::VERSION_NUM;
        $ref = System::getBaseUrl();
        $port = (($urlArray['scheme'] == 'https') ? 443 : 80);
        if (ini_get('allow_url_fopen')) {
            // handle SSL connections
            $path_query = (isset($urlArray['query']) ? $urlArray['path'] . $urlArray['query'] : $urlArray['path']);
            $host = ($port==443 ? "ssl://$urlArray[host]" : $urlArray['host']);
            $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
            if (!$fp) {
                return false;
            } else {
                $out = "GET $path_query? HTTP/1.1\r\n";
                $out .= "User-Agent: $userAgent\r\n";
                $out .= "Referer: $ref\r\n";
                $out .= "Host: $urlArray[host]\r\n";
                $out .= "Connection: Close\r\n\r\n";
                fwrite($fp, $out);
                while (!feof($fp)) {
                    $data .= fgets($fp, 1024);
                }
                fclose($fp);
                $dataArray = explode("\r\n\r\n", $data);

                return $dataArray[1];
            }
        } elseif (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
            curl_setopt($ch, CURLOPT_URL, "$url?");
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
            curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
            curl_setopt($ch, CURLOPT_REFERER, $ref);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            if (!ini_get('safe_mode') && !ini_get('open_basedir')) {
                // This option doesnt work in safe_mode or with open_basedir set in php.ini
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
            }
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            $data = curl_exec($ch);
            if (!$data && $port=443) {
                // retry non ssl
                $url = str_replace('https://', 'http://', $url);
                curl_setopt($ch, CURLOPT_URL, "$url?");
                $data = @curl_exec($ch);
            }
            //$headers = curl_getinfo($ch);
            curl_close($ch);

            return $data;
        } else {
            return false;
        }
    }

    /**
     * helper function to sort modules
     *
     * @param $a array first item to compare 
     * @param $b array second item to compare
     *
     * @return int < 0 if module a should be ordered before module b > 0 otherwise
     */
    public static function _sortAdminModsByOrder($a,$b)
    {
        if ((int)$a['order'] == (int)$b['order']) {
            return strcmp($a['modname'], $b['modname']);
        }
        if((int)$a['order'] > (int)$b['order']) {
            return 1;
        }
        if((int)$a['order'] < (int)$b['order']) {
            return -1;
        }
    }
}
