<?php
/**
 * 2007-2017 PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 *
 *  @author    PrestaShop SA <contact@prestashop.com>
 *  @copyright 2007-2017 PrestaShop SA
 *  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 *  International Registered Trademark & Property of PrestaShop SA
 */

class PaypalErrorModuleFrontController extends ModuleFrontController
{
    /**
     * @see FrontController::initContent()
     */
    public function initContent()
    {
        parent::initContent();
        $error_code = Tools::getValue('L_ERRORCODE0');
        $errors = $this->getErrorMsg();
        $error_msg = $errors[$error_code]?$errors[$error_code]:$errors['00000'];
        $this->context->smarty->assign(array(
            'error_paypal' => $error_msg,
        ));

        $this->setTemplate('module:paypal/views/templates/front/payment_error.tpl');
    }

    public function getErrorMsg()
    {
        $module = Module::getInstanceByName('paypal');
        $errors = array(
            '00000' => $module->l('Unexpected error occurred.'),
            '10002' => $module->l('You do not have permissions to make this API call'),
            '81002' => $module->l('Method Specified is not Supported'),
            '10413' => $module->l('The totals of the cart item amounts do not match order amounts.'),
            '10400' => $module->l('Order total is missing'),
            '10006' => $module->l('Version is not supported'),
            '10605' => $module->l('Currency is not supported'),
        );
        return $errors;
    }
}