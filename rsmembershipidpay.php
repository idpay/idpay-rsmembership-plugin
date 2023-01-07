<?php

ini_set('display_errors', 1);
defined('_JEXEC') or die('Restricted access');

use RSMembership;

class plgSystemRSMembershipIDPay extends JPlugin
{
    public const TRANSACTION_STATE_COMPLETE = 'completed';
    public const TRANSACTION_STATE_DENIED = 'denied';
    public const TRANSACTION_STATE_PENDING = 'pending';

    public $application;


    public function __construct(&$subject, $config)
    {
        parent::__construct($subject, $config);
        $this->loadLanguage('plg_system_rsmembership', JPATH_ADMINISTRATOR);
        $this->loadLanguage('plg_system_rsmembershipidpay', JPATH_ADMINISTRATOR);
        RSMembership::addPlugin( $this->translate('OPTION_NAME'), 'rsmembershipidpay');
    }


    public function onMembershipPayment($plugin, $data, $extra,  $membership, $transaction, $html)
    {
            /**  */

            $app = JFactory::getApplication();
            if ($plugin != 'rsmembershipidpay')  return;

            $api_key = $this->params->get('api_key');
            $sandbox = $this->params->get('sandbox') == 'no' ? 'false' : 'true';
            $extra_total = 0;
            foreach ($extra as $row) {
                $extra_total += $row->price;
            }

            $amount = $transaction->price + $extra_total;
            $amount *= $this->params->get('currency') == 'rial' ? 1 : 10;

            if($transaction->get('status') == self::TRANSACTION_STATE_PENDING)
            {
                $transaction->tax_type = '0';
                $transaction->tax_value = '0';
                $transaction->tax_percent_value = '0';
                $transaction->hash = '';
                $transaction->custom = $membership->activation;
                $transaction->response_log = '';
                $transaction->store();
            }
            /* Activation (s) type =>
             [
                0 => Manual - activation will require a Joomla! administrator to review the membership request
                1 => Automatic - will automatically activate the membership when the payment
                2 => Instant - will activate the membership without waiting for payment
            ]
            */

            switch ($membership->activation){
                case 0:
                case 1:
                        $callback = JURI::base() . 'index.php?option=com_rsmembership&idpayPayment=1';
                        $callback = JRoute::_($callback, false);
                        $orderId = $transaction->id;

                        $data = [
                            'order_id' => $orderId,
                            'amount'   => $amount,
                            'name'     => !empty($data->name)? $data->name : '',
                            'phone'    => !empty($data->fields['phone'])? $data->fields['phone'] : '',
                            'mail'     => !empty($data->email)? $data->email : '',
                            'desc'     => htmlentities( $this->translate('PARAMS_DESC') . $orderId, ENT_COMPAT, 'utf-8'),
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
                            $transaction->status = self::TRANSACTION_STATE_DENIED;
                            $transaction->store();

                            $msg = sprintf( $this->translate('ERROR_PAYMENT'), $http_status, $result->error_code, $result->error_message );
                            RSMembership::saveTransactionLog($msg, $orderId);
                            $url = JRoute::_(JURI::base() . 'index.php?option=com_rsmembership&view=mymemberships', false);
                            $app->redirect($url,200);
                        }

                        RSMembership::saveTransactionLog( $this->translate('LOG_GOTO_BANK'), $orderId );

                        $hash = hash('sha256',($orderId . $result->id) ) ;

                        $transaction->hash = $hash;
                        $transaction->store();

                        $session  = $app->getSession();
                        $session->set('transaction_hash', $hash);

                        $app->redirect($result->link);
                break;

                case 2:
                    $orderId = $transaction->id;

                    $transaction->custom = $membership->activation;
                    $transaction->tax_type = '0';
                    $transaction->tax_value = '0';
                    $transaction->tax_percent_value = '0';
                    $transaction->hash = hash('sha256',($orderId . time()) );
                    $transaction->response_log = '';
                    $transaction->status = self::TRANSACTION_STATE_COMPLETE;
                    $transaction->store();
                    $membership->store();

                    RSMembership::finalize($orderId);
                    RSMembership::approve($orderId,true);
                    $msg = $this->idpay_get_filled_message( uniqid(), $orderId, 'success_massage' );
                    RSMembership::saveTransactionLog($msg, $transaction->id);

                    $url = JRoute::_(JURI::base() . 'index.php?option=com_rsmembership&view=mymemberships', false);
                    $app->redirect($url,200);

                break;
            }

            exit;
    }

    public function getValue(string $name){
        /** @var Joomla\CMS\Input\Input $request */
        $request   = $this->application->input;
        return $request->getMethod() == 'POST'   ? $request->post->get($name) : $request->get->get( $name );
    }

    public function getOrder($db,$orderId){
        $query = $db->getQuery(true);
        $query->select('*')
            ->from($db->quoteName('#__rsmembership_transactions'))
            ->where($db->quoteName('id') . ' = ' . $db->quote($orderId));
        $db->setQuery($query);
        return $db->loadObject();
    }

    public function updateOrder($db,$transaction,$status,$trackId = null){
        $query = $db->getQuery(true);
        $query->clear();
        $query->update($db->quoteName('#__rsmembership_transactions'))
            ->set($db->quoteName('hash') . ' = ' . $db->quote($trackId ?? ''))
            ->set($db->quoteName('status') . ' = ' . $db->quote($status))
            ->where($db->quoteName('id') . ' = ' . $db->quote($transaction->id));

        $db->setQuery($query);
       return $db->execute();
    }

    public function isNotDoubleSpending($reference,$receivedHash){
        return $reference->hash != $receivedHash;
    }

    protected function onPaymentNotification($app)
    {
        $this->application = $app;
        $session  = $app->getSession();
        $db = JFactory::getContainer()->get('DatabaseDriver');

        $status   =  $this->getValue('status');
        $track_id   =  $this->getValue('track_id');
        $id   =  $this->getValue('id');
        $order_id   =  $this->getValue('order_id');
        $hash = $session->get('transaction_hash');
        $transaction = $this->getOrder($db,$order_id);
        $activationType = (int) $transaction->custom;

        $validation = [
            $status,
            $track_id,
            $id,
            $order_id,
            $hash,
            $transaction,
            $activationType,
        ];

        if($this->isOnceEmpty($validation) || $status != 10  ||$transaction->status != self::TRANSACTION_STATE_PENDING || $this->isNotDoubleSpending($transaction,$hash))
        {
            throw new Exception( $this->translate('ERROR_EMPTY_PARAMS') );
        }

        try {
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

                $this->updateOrder($db,$transaction,self::TRANSACTION_STATE_COMPLETE,$verify_track_id);
                RSMembership::finalize($transaction->id);

                if ($activationType != 0)
                {
                    RSMembership::approve($transaction->id,true);
                }

                $msg = $this->idpay_get_filled_message( $verify_track_id, $verify_order_id, 'success_massage' );
                RSMembership::saveTransactionLog($msg, $transaction->id);

                $url = JRoute::_(JURI::base() . 'index.php?option=com_rsmembership&view=mymemberships', false);
                $app->redirect($url);
                die();
            }
            else{
                $msg = $this->idpay_get_filled_message( $verify_track_id, $verify_order_id, 'failed_massage' );
                throw new Exception($msg);
            }

        } catch (Exception $e) {
            if($transaction){
                $this->updateOrder($db,$transaction,self::TRANSACTION_STATE_COMPLETE);
                RSMembership::deny($transaction->id);
                RSMembership::saveTransactionLog($e->getMessage(), $transaction->id );
            }
            $app->enqueueMessage($e->getMessage(), 'error');
            $url = JRoute::_(JURI::base() . 'index.php?option=com_rsmembership&view=mymemberships', false);
            $app->redirect($url);
        }
    }

    public  function isOnceEmpty( array $variables ): bool {
        foreach ( $variables as $variable ) {
            if ( empty( $variable ) ) {
                return true;
            }
        }

        return false;
    }

    public function getLimitations() {
        $msg = $this->translate('LIMITAION');
        return $msg;
    }

    public function onAfterDispatch()
    {
        $app = JFactory::getApplication();
        if ($app->input->getBoolean('idpayPayment')) {
            $this->onPaymentNotification($app);
        }
    }

    public function idpay_get_filled_message( $track_id, $order_id, $type ) {
        return str_replace( [ "{track_id}", "{order_id}" ], [
            $track_id,
            $order_id,
        ], $this->params->get( $type, '' ) );
    }

    protected function translate($key)
    {
        return JText::_('PLG_RSM_IDPAY_' . strtoupper($key));
    }
}
