<?php


if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}


use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class TasdidRabbitMQ
{
    private $connection;
    private $channel;
    private $config;

    public function __construct()
    {
        $this->config = $this->getRabbitMQConfig();
    }

    private function getRabbitMQConfig()
    {
        return [
            'host' => '',
            'port' => 5672,
            'user' => '',
            'password' => '',
            'vhost' => '/tasdid-whmcs',
        ];
    }

    public function connect()
    {
        try {
            $this->connection = new AMQPStreamConnection(
                $this->config['host'],
                $this->config['port'],
                $this->config['user'],
                $this->config['password'],
                $this->config['vhost']
            );
            $this->channel = $this->connection->channel();
        } catch (Exception $e) {
            logModuleCall(
                'tasdidmodule',
                'RabbitMQ Connection Error',
                '',
                '',
                $e->getMessage(),
                []
            );
            throw new Exception('Could not connect to RabbitMQ: ' . $e->getMessage());
        }
    }


    public function declareQueue($queueName)
    {
        if ($this->channel) {
            $this->channel->queue_declare($queueName, false, true, false, false);
        }
    }


    public function sendMessage($queueName, $message)
    {
        if (!$this->channel) {
            $this->connect();
        }

        try {
            $this->declareQueue($queueName);

            $msg = new AMQPMessage(
                json_encode($message),
                ['delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT]
            );

            $this->channel->basic_publish($msg, '', $queueName);
            logModuleCall('tasdid', 'rabbitmq_send', $queueName, $message, [], []);
            return true;
        } catch (Exception $e) {
            logModuleCall('tasdid', 'rabbitmq_send_error', $queueName, $e->getMessage(), [], []);
            return false;
        }
    }


    public function consume($queueName, $callback)
    {
        if (!$this->channel) {
            $this->connect();
        }

        try {
            $this->declareQueue($queueName);

            $this->channel->basic_consume(
                $queueName,
                '',
                false,
                true,
                false,
                false,
                $callback
            );

            while ($this->channel->is_consuming()) {
                $this->channel->wait();
            }
        } catch (Exception $e) {
            logModuleCall('tasdid', 'rabbitmq_consume_error', $queueName, $e->getMessage(), [], []);
        }
    }

    public function close()
    {
        if ($this->channel) {
            $this->channel->close();
        }
        if ($this->connection) {
            $this->connection->close();
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}

function getInvoiceQueueName($invoiceId)
{
    return 'whmcs_tasdid_invoice_' . $invoiceId;
}