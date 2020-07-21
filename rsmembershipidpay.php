<?php
/**
 * @package       RSMembership!
 * @copyright (C) 2009-2020 www.rsjoomla.com
 * @license       GPL, http://www.gnu.org/licenses/gpl-2.0.html
 */
/**
 * @plugin RSMembership IDPay Payment
 * @author Meysam Razmi(meysamrazmi), vispa
 */

ini_set('display_errors', 1);
defined('_JEXEC') or die('Restricted access');
require_once JPATH_ADMINISTRATOR . '/components/com_rsmembership/helpers/rsmembership.php';

class plgSystemRSMembershipIDPay extends JPlugin
{
    public function __construct(&$subject, $config)
    {
        parent::__construct($subject, $config);
        // load languages
        $this->loadLanguage('plg_system_rsmembership', JPATH_ADMINISTRATOR);
        $this->loadLanguage('plg_system_rsmembershipidpay', JPATH_ADMINISTRATOR);

        RSMembership::addPlugin( $this->translate('OPTION_NAME'), 'rsmembershipidpay');
    }

    /**
     * call when payment starts
     *
     * @param $plugin
     * @param $data
     * @param $extra
     * @param $membership
     * @param $transaction
     * @param $html
     */
    public function onMembershipPayment($plugin, $data, $extra, $membership, $transaction, $html)
    {
        $app = JFactory::getApplication();

        try {
            if ($plugin != 'rsmembershipidpay')
                return;

            $api_key = trim($this->params->get('api_key'));
            $sandbox = $this->params->get('sandbox') == 'no' ? 'false' : 'true';

            $extra_total = 0;
            foreach ($extra as $row) {
                $extra_total += $row->price;
            }

            $amount = $transaction->price + $extra_total;
            $amount *= $this->params->get('currency') == 'rial' ? 1 : 10;

            $transaction->custom = md5($transaction->params . ' ' . time());
            if ($membership->activation == 2) {
                $transaction->status = 'completed';
            }
            $transaction->store();

            $callback = JURI::base() . 'index.php?option=com_rsmembership&idpayPayment=1';
            $callback = JRoute::_($callback, false);
            $session  = JFactory::getSession();
            $session->set('transaction_custom', $transaction->custom);
            $session->set('membership_id', $membership->id);

            $data = [
                'order_id' => $transaction->id,
                'amount'   => $amount,
                'name'     => !empty($data->name)? $data->name : '',
                'phone'    => !empty($data->fields['phone'])? $data->fields['phone'] : '',
                'mail'     => !empty($data->email)? $data->email : '',
                'desc'     => htmlentities( $this->translate('PARAMS_DESC') . $transaction->id, ENT_COMPAT, 'utf-8'),
                'callback' => $callback,
            ];

            $ch = curl_init( 'https://api.idpay.ir/v1.1/payment' );
            curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $data ) );
            curl_setopt( $ch, CURLOPT_RETURNTRANSFER, TRUE );
            curl_setopt( $ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'X-API-KEY:' . $api_key,
                'X-SANDBOX:' . $sandbox,
            ] );

            $result      = curl_exec( $ch );
            $result      = json_decode( $result );
            $http_status = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
            curl_close( $ch );

            if ( $http_status != 201 || empty( $result ) || empty( $result->id ) || empty( $result->link ) )
            {
                $transaction->status = 'denied';
                $transaction->store();

                $msg = sprintf( $this->translate('ERROR_PAYMENT'), $http_status, $result->error_code, $result->error_message );
                RSMembership::saveTransactionLog($msg, $transaction->id);

                throw new Exception($msg);
            }

            RSMembership::saveTransactionLog( $this->translate('LOG_GOTO_BANK'), $transaction->id );
            $app->redirect($result->link);

            exit;
        }
        catch (Exception $e) {
            $app->redirect(JRoute::_(JURI::base() . 'index.php/component/rsmembership/view-membership-details/' . $membership->id, false), $e->getMessage(), 'error');
            exit;
        }
    }

    public function getLimitations() {
        $msg = $this->translate('LIMITAION');
        return $msg;
    }

    /**
     * after payment completed
     * calls function onPaymentNotification()
     */
    public function onAfterDispatch()
    {
        $app = JFactory::getApplication();
        if ($app->input->getBoolean('idpayPayment')) {
            $this->onPaymentNotification($app);
        }
    }

    /**
     * process payment verification and approve subscription
     * @param $app
     */
    protected function onPaymentNotification($app)
    {
        $input    = $app->input;
        $jinput   = $app->input;
        $status   = empty( $jinput->post->get( 'status' ) ) ? NULL : $jinput->post->get( 'status' );
        $track_id = empty( $jinput->post->get( 'track_id' ) ) ? NULL : $jinput->post->get( 'track_id' );
        $id       = empty( $jinput->post->get( 'id' ) ) ? NULL : $jinput->post->get( 'id' );
        $order_id = empty( $jinput->post->get( 'order_id' ) ) ? NULL : $jinput->post->get( 'order_id' );

        $session  = JFactory::getSession();

        $transaction_custom = $session->get('transaction_custom');

        $db = JFactory::getDbo();
        $query = $db->getQuery(true);
        $query->select('*')
            ->from($db->quoteName('#__rsmembership_transactions'))
            ->where($db->quoteName('status') . ' != ' . $db->quote('completed'))
            ->where($db->quoteName('custom') . ' = ' . $db->quote($transaction_custom));
        $db->setQuery($query);
        $transaction = @$db->loadObject();

        try {
            if ( empty( $id ) || empty( $order_id ) )
                throw new Exception( $this->translate('ERROR_EMPTY_PARAMS') );

            if (!$transaction)
                throw new Exception( $this->translate('ERROR_NOT_FOUND') );

            // Check double spending.
            if ( $transaction->id != $order_id )
                throw new Exception( $this->translate('ERROR_WRONG_PARAMS') );

            if ( $status != 10 )
                throw new Exception( sprintf( $this->translate('ERROR_FAILED'), $this->translate('CODE_'. $status), $status, $track_id ) );

            $api_key = $this->params->get( 'api_key', '' );
            $sandbox = $this->params->get( 'sandbox', '' ) == 'no' ? 'false' : 'true';

            $data = [
                'id'       => $id,
                'order_id' => $order_id,
            ];

            $ch = curl_init();
            curl_setopt( $ch, CURLOPT_URL, 'https://api.idpay.ir/v1.1/payment/verify' );
            curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $data ) );
            curl_setopt( $ch, CURLOPT_RETURNTRANSFER, TRUE );
            curl_setopt( $ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'X-API-KEY:' . $api_key,
                'X-SANDBOX:' . $sandbox,
            ] );

            $result      = curl_exec( $ch );
            $result      = json_decode( $result );
            $http_status = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
            curl_close( $ch );

            if ( $http_status != 200 )
            {
                $msg = sprintf( $this->translate('ERROR_FAILED_VERIFY'), $http_status, $result->error_code, $result->error_message );
                throw new Exception($msg);
            }

            $verify_order_id = empty( $result->order_id ) ? NULL : $result->order_id;
            $verify_track_id = empty( $result->track_id ) ? NULL : $result->track_id;
            $status = $result->status;

            if ($status == 100) {
                $query->clear();
                $query->update($db->quoteName('#__rsmembership_transactions'))
                    ->set($db->quoteName('hash') . ' = ' . $db->quote($verify_track_id))
                    ->where($db->quoteName('id') . ' = ' . $db->quote($transaction->id));

                $db->setQuery($query);
                $db->execute();

                $membership_id = $session->get('membership_id');

                if (!$membership_id)
                    throw new Exception( $this->translate('ERROR_NOT_FOUND'));

                $query->clear()
                    ->select('activation')
                    ->from($db->quoteName('#__rsmembership_memberships'))
                    ->where($db->quoteName('id') . ' = ' . $db->quote((int)$membership_id));
                $db->setQuery($query);
                $activation = $db->loadResult();

                if ($activation) // activation == 0 => activation is manual
                {
                    RSMembership::approve($transaction->id);
                }

                $msg = $this->idpay_get_filled_message( $verify_track_id, $verify_order_id, 'success_massage' );
                RSMembership::saveTransactionLog($msg, $transaction->id);

                $app->redirect(JRoute::_(JURI::base() . 'index.php?option=com_rsmembership&view=mymemberships', false), $msg, 'message');
            }

            $msg = $this->idpay_get_filled_message( $verify_track_id, $verify_order_id, 'failed_massage' );
            throw new Exception($msg);

        } catch (Exception $e) {
            if($transaction){
                RSMembership::deny($transaction->id);
                RSMembership::saveTransactionLog($e->getMessage(), $transaction->id );
            }
            $app->enqueueMessage($e->getMessage(), 'error');
        }
    }

    /**
     * fill message in gateway setting with track_id and order_id
     *
     * @param $track_id
     * @param $order_id
     * @param $type | success or error
     *
     * @return String
     */
    public function idpay_get_filled_message( $track_id, $order_id, $type ) {
        return str_replace( [ "{track_id}", "{order_id}" ], [
            $track_id,
            $order_id,
        ], $this->params->get( $type, '' ) );
    }

    /**
     * translate plugin language files
     * @param $key
     * @return mixed
     */
    protected function translate($key)
    {
        return JText::_('PLG_RSM_IDPAY_' . strtoupper($key));
    }
}