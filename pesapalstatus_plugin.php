<?php

/**
 * Pesapal Payment Status Plugin handler
 *
 */
class PesapalstatusPlugin extends Plugin {

    private static $version = "0.0.1";

    private static $authors = array(array('name' => "Webline Technologes", 'url' => "http://www.yatosha.com"));

    public function __construct() {
        $this->loadConfig(dirname(__FILE__) . DS . 'config.json');
        Language::loadLang("pesapalstatus_plugin", null, dirname(__FILE__) . DS . "language" . DS);
        Loader::load(dirname(__FILE__) . DS . "lib" . DS . "OAuth.php");
        Loader::load(dirname(__FILE__) . DS . "lib" . DS . "xmlhttprequest.php");
        Loader::loadModels($this, array('GatewayManager'));
        Loader::loadComponents($this, ['Input','Record']);
    }

    /**
     * Returns the name of this plugin
     *
     * @return string The common name of this plugin
     */
    public function getName() {
        return Language::_("Pesapalstatus.name", true);
    }

    /**
     * Returns the version of this plugin
     *
     * @return string The current version of this plugin
     */
    public function getVersion() {
        return self::$version;
    }

    /**
     * Returns the name and URL for the authors of this plugin
     *
     * @return array The name and URL of the authors of this plugin
     */
    public function getAuthors() {
        return self::$authors;
    }

    /**
     * Performs any necessary bootstraping actions
     *
     * @param int $plugin_id The ID of the plugin being installed
     */
    public function install($plugin_id) {
        Loader::loadModels($this, array('CronTasks'));
        $this->addCronTasks($this->getCronTasks());
    }

    /**
     * Performs any necessary cleanup actions
     *
     * @param int $plugin_id The ID of the plugin being uninstalled
     * @param boolean $last_instance True if $plugin_id is the last instance across all companies for this plugin, false otherwise
     */
    public function uninstall($plugin_id, $last_instance) {
        Loader::loadModels(
            $this,
            array('CronTasks')
        );

        $cron_tasks = $this->getCronTasks();

        if ($last_instance) {
            foreach ($cron_tasks as $task) {
                $cron_task = $this->CronTasks
                    ->getByKey($task['key'], $task['plugin_dir']);
                if ($cron_task) {
                    $this->CronTasks->delete($cron_task->id, $task['plugin_dir']);
                }
            }
        }

        foreach ($cron_tasks as $task) {
            $cron_task_run = $this->CronTasks
                ->getTaskRunByKey($task['key'], $task['plugin_dir']);
            if ($cron_task_run) {
                $this->CronTasks->deleteTaskRun($cron_task_run->task_run_id);
            }
        }
    }

    public function cron($key)
    {
        if ($key == 'check_transaction_status') {
            $this->check_transaction_status();
        }
    }

    /**
     * Attempts to add new cron tasks for this plugin
     *
     * @param array $tasks A list of cron tasks to add
     */
    private function addCronTasks(array $tasks)
    {
        foreach ($tasks as $task) {
            $task_id = $this->CronTasks->add($task);

            if (!$task_id) {
                $cron_task = $this->CronTasks->getByKey(
                    $task['key'],
                    $task['plugin_dir']
                );
                if ($cron_task) {
                    $task_id = $cron_task->id;
                }
            }

            if ($task_id) {
                $task_vars = array('enabled' => $task['enabled']);
                if ($task['type'] === "time") {
                    $task_vars['time'] = $task['type_value'];
                } else {
                    $task_vars['interval'] = $task['type_value'];
                }

                $this->CronTasks->addTaskRun($task_id, $task_vars);
            }
        }
    }

    private function getCronTasks()
    {
        return array(
            array(
                'key' => 'check_transaction_status',
                'plugin_dir' => 'pesapalstatus',
                'name' => Language::_(
                    'Pesapalstatus.getCronTasks.check_transaction_status',
                    true
                ),
                'description' => Language::_(
                    'Pesapalstatus.getCronTasks.check_transaction_status',
                    true
                ),
                'type' => 'interval',
                'type_value' =>'5' ,
                'enabled' => 1
            )
        );
    }

   private function check_transaction_status() {

	try {

	    $companyId = Configure::get('Blesta.company_id');

	    $gateways = $this->GatewayManager->getAll($companyId);

	    if(empty($gateways['nonmerchant'])) return;

	    $parameters = array();
	    foreach($gateways['nonmerchant'] as $gateway) {

		if($gateway->class != 'pesapal') continue;

		$gw = $this->GatewayManager->get($gateway->id);
		$metas = $gw->meta;
		$currencies = $gw->currencies;

		$parameters['gateway_id'] = $gateway->id;

		foreach($metas as $meta) {
			$parameters[$meta->key] = $meta->value;		
		}
	    }

	    if($parameters) {

		$transactionStatus = "pending";
		$demoMode = isset($parameters['use_demo']) && $parameters['use_demo'] == "true" ? 1 : 0;
		$consumerKey = isset($parameters['consumer_key']) ? $parameters['consumer_key'] : "";
		$secretKey = isset($parameters['secret_key']) ? $parameters['secret_key'] : "";

		if($demoMode == 1) {
			$consumerKey = isset($parameters['demo_consumer_key']) ? $parameters['demo_consumer_key'] : "";
			$secretKey = isset($parameters['demo_secret_key']) ? $parameters['demo_secret_key'] : "";
		}

		if($consumerKey && $secretKey) {

					$req_dump = print_r($consumerKey . "|" . $secretKey, TRUE);
					$fp = fopen(PLUGINDIR . 'pesapalstatus' . DS . 'debug.log', 'a');
					fwrite($fp, $req_dump . "\n");
					fclose($fp);

        		$transactions = $this->Record->select(['transactions.id','transactions.status', 'transactions.transaction_id'])
            					->from('transactions')
            					->innerJoin('clients', 'clients.id', '=', 'transactions.client_id', false)
            					->innerJoin('client_groups', 'client_groups.id', '=', 'clients.client_group_id', false)
            					->where('client_groups.company_id', '=', $companyId)
	    					->where('gateway_id' ,'=' ,$parameters['gateway_id'])
            					->where('transactions.status', '=', $transactionStatus)
            					->fetchAll();

			if(count($transactions) > 0) {

				foreach($transactions as $transaction) {

					$status = $this->checkStatus($consumerKey,$secretKey,$transaction->transaction_id,1001,$demoMode);

					$req_dump = print_r($transaction->transaction_id . "|" . $status, TRUE);
					$fp = fopen(PLUGINDIR . 'pesapalstatus' . DS . 'debug.log', 'a');
					fwrite($fp, $req_dump . "\n");
					fclose($fp);

					$req_dump = print_r("5HS91MCM3Z9", TRUE);
					$fp = fopen(PLUGINDIR . 'pesapalstatus' . DS . 'debug.log', 'a');
					fwrite($fp, $req_dump . "\n");
					fclose($fp);

					if($status == "COMPLETED") {
        					$this->Record->where('id', '=', $transaction->id)
                                			->update('transactions', ['status' => 'approved']);
					} else if($status == "REFUNDED") {
        					$this->Record->where('id', '=', $transaction->id)
                                			->update('transactions', ['status' => 'refunded']);
					}
					else if ($status == "FAILED") {
        					$this->Record->where('id', '=', $transaction->id)
                                			->update('transactions', ['status' => 'error']);
					}

				}
			}		
		}
	    }

        } catch (Exception $e) {
            $this->Input->setErrors(['db' => ['create' => $e->getMessage()]]);
            return;
        }
   }

   private function checkStatus($consumerKey,$secretKey,$trackingID, $referenceNO, $demo=0){
	
	$token = $params = NULL;
	$statusrequest = "https://www.pesapal.com/API/QueryPaymentStatus";
	if($demo == 1) {
		$statusrequest = "http://demo.pesapal.com/api/querypaymentstatus";
	}

					$req_dump = print_r($statusrequest, TRUE);
					$fp = fopen(PLUGINDIR . 'pesapalstatus' . DS . 'debug.log', 'a');
					fwrite($fp, $req_dump . "\n");
					fclose($fp);

	$consumer_key = $consumerKey;
	$consumer_secret = $secretKey;
	$signature_method = new OAuthSignatureMethod_HMAC_SHA1();
	
	$consumer = new OAuthConsumer($consumer_key,$consumer_secret);

	//get transaction status
	$request_status = OAuthRequest::from_consumer_and_token($consumer, $token,"GET", $statusrequest, $params);
	$request_status->set_parameter("pesapal_merchant_reference", $referenceNO);
	$request_status->set_parameter("pesapal_transaction_tracking_id",$trackingID);
	$request_status->sign_request($signature_method, $consumer, $token);

        return $this->curlRequest($request_status);		
   }

    private function curlRequest($request_status) {

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $request_status);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HEADER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        if (defined('CURL_PROXY_REQUIRED')) if (CURL_PROXY_REQUIRED == 'True') {
            $proxy_tunnel_flag = (
                defined('CURL_PROXY_TUNNEL_FLAG')
                && strtoupper(CURL_PROXY_TUNNEL_FLAG) == 'FALSE'
            ) ? false : true;
            curl_setopt($ch, CURLOPT_HTTPPROXYTUNNEL, $proxy_tunnel_flag);
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
            curl_setopt($ch, CURLOPT_PROXY, CURL_PROXY_SERVER_DETAILS);
        }

        $response = curl_exec($ch);
					$req_dump = print_r($response, TRUE);
					$fp = fopen(PLUGINDIR . 'pesapalstatus' . DS . 'debug.log', 'a');
					fwrite($fp, $req_dump . "\n");
					fclose($fp);

        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $raw_header = substr($response, 0, $header_size - 4);
        $headerArray = explode("\r\n\r\n", $raw_header);
        $header = $headerArray[count($headerArray) - 1];

        //transaction status
        $elements = preg_split("/=/", substr($response, $header_size));
        $pesapal_response_data = $elements[1];

        return $pesapal_response_data;

    }
}
