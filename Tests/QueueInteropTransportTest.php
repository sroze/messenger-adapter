<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Enqueue\MessengerAdapter\Tests;

use Enqueue\AmqpTools\DelayStrategyAware;
use Enqueue\AmqpTools\RabbitMqDelayPluginDelayStrategy;
use Enqueue\MessengerAdapter\QueueInteropTransport;
use Interop\Queue\Consumer;
use Interop\Queue\Queue;
use Interop\Queue\Topic;
use PHPUnit\Framework\TestCase;
use Interop\Queue\Context;
use Interop\Queue\Producer;
use Interop\Queue\Message;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Enqueue\MessengerAdapter\ContextManager;
use Enqueue\MessengerAdapter\EnvelopeItem\TransportConfiguration;
use Interop\Queue\Exception\Exception;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Enqueue\MessengerAdapter\Exception\MissingMessageMetadataSetterException;
use Enqueue\MessengerAdapter\Tests\Fixtures\DecoratedPsrMessage;

class QueueInteropTransportTest extends TestCase
{
    public function testInterfaces()
    {
        $transport = $this->getTransport();

        $this->assertInstanceOf(TransportInterface::class, $transport);
    }

    public function testSendAndEnsuresTheInfrastructureExistsWithDebug()
    {
        $topicName = 'topic';
        $queueName = 'queue';
        $message = new \stdClass();
        $message->foo = 'bar';
        $envelope = new Envelope($message);

        $psrMessageProphecy = $this->prophesize(Message::class);
        $psrMessage = $psrMessageProphecy->reveal();
        $topicProphecy = $this->prophesize(Topic::class);
        $topic = $topicProphecy->reveal();

        $producerProphecy = $this->prophesize(ProducerWithDelay::class);
        $producerProphecy->setDeliveryDelay(100)->shouldBeCalled();
        $producerProphecy->setDelayStrategy(new RabbitMqDelayPluginDelayStrategy())->shouldBeCalled();
        $producerProphecy->setPriority(100)->shouldBeCalled();
        $producerProphecy->setTimeToLive(100)->shouldBeCalled();
        $producerProphecy->send($topic, $psrMessage)->shouldBeCalled();

        $contextProphecy = $this->prophesize(Context::class);
        $contextProphecy->createTopic($topicName)->shouldBeCalled()->willReturn($topic);
        $contextProphecy->createProducer()->shouldBeCalled()->willReturn($producerProphecy->reveal());
        $contextProphecy->createMessage('foo', array(), array())->shouldBeCalled()->willReturn($psrMessage);

        $contextManagerProphecy = $this->prophesize(ContextManager::class);
        $contextManagerProphecy->context()->shouldBeCalled()->willReturn($contextProphecy->reveal());
        $contextManagerProphecy->ensureExists(array(
            'topic' => $topicName,
            'topicOptions' => array('name' => $topicName),
            'queue' => $queueName,
            'queueOptions' => array('name' => $queueName),
        ))->shouldBeCalled();

        $encoderProphecy = $this->prophesize(SerializerInterface::class);
        $encoderProphecy->encode($envelope)->shouldBeCalled()->willReturn(array('body' => 'foo'));

        $transport = $this->getTransport(
            $encoderProphecy->reveal(),
            $contextManagerProphecy->reveal(),
            array(
                'topic' => array('name' => $topicName),
                'queue' => array('name' => $queueName),
                'deliveryDelay' => 100,
                'priority' => 100,
                'timeToLive' => 100,
                'receiveTimeout' => 100,
            ),
            true
        );

        $this->assertSame($envelope, $transport->send($envelope));
    }

    public function testSendWithOptionsFromConfiguration()
    {
        $topicName = 'topic';
        $queueName = 'queue';
        $message = new \stdClass();
        $message->foo = 'bar';
        $options = array(
            'deliveryDelay' => 500,
            'priority' => 500,
            'timeToLive' => 500,
            'receiveTimeout' => 500,
        );
        $envelope = (new Envelope($message))->with(new TransportConfiguration(array('options' => $options)));;

        $psrMessageProphecy = $this->prophesize(Message::class);
        $psrMessage = $psrMessageProphecy->reveal();
        $topicProphecy = $this->prophesize(Topic::class);
        $topic = $topicProphecy->reveal();

        $producerProphecy = $this->prophesize(ProducerWithDelay::class);
        $producerProphecy->setDeliveryDelay(500)->shouldBeCalled();
        $producerProphecy->setDelayStrategy(new RabbitMqDelayPluginDelayStrategy())->shouldBeCalled();
        $producerProphecy->setPriority(500)->shouldBeCalled();
        $producerProphecy->setTimeToLive(500)->shouldBeCalled();
        $producerProphecy->send($topic, $psrMessage)->shouldBeCalled();

        $contextProphecy = $this->prophesize(Context::class);
        $contextProphecy->createTopic($topicName)->shouldBeCalled()->willReturn($topic);
        $contextProphecy->createProducer()->shouldBeCalled()->willReturn($producerProphecy->reveal());
        $contextProphecy->createMessage('foo', array(), array())->shouldBeCalled()->willReturn($psrMessage);

        $contextManagerProphecy = $this->prophesize(ContextManager::class);
        $contextManagerProphecy->context()->shouldBeCalled()->willReturn($contextProphecy->reveal());
        $contextManagerProphecy->ensureExists(array(
            'topic' => $topicName,
            'topicOptions' => array('name' => $topicName),
            'queue' => $queueName,
            'queueOptions' => array('name' => $queueName),
        ))->shouldBeCalled();

        $encoderProphecy = $this->prophesize(SerializerInterface::class);
        $encoderProphecy->encode($envelope)->shouldBeCalled()->willReturn(array('body' => 'foo'));

        $transport = $this->getTransport(
            $encoderProphecy->reveal(),
            $contextManagerProphecy->reveal(),
            array(
                'topic' => array('name' => $topicName),
                'queue' => array('name' => $queueName),
                'deliveryDelay' => 100,
                'delayStrategy' => RabbitMqDelayPluginDelayStrategy::class,
                'priority' => 100,
                'timeToLive' => 100,
                'receiveTimeout' => 100,
            ),
            true
        );

        $this->assertSame($envelope, $transport->send($envelope));
    }

    public function testSendWithoutDebugWillNotVerifyTheInfrastructureForPerformanceReasons()
    {
        $topicName = 'topic';
        $queueName = 'queue';
        $message = new \stdClass();
        $message->foo = 'bar';
        $envelope = new Envelope($message);

        $psrMessageProphecy = $this->prophesize(Message::class);
        $psrMessage = $psrMessageProphecy->reveal();
        $topicProphecy = $this->prophesize(Topic::class);
        $topic = $topicProphecy->reveal();

        $producerProphecy = $this->prophesize(Producer::class);
        $producerProphecy->send($topic, $psrMessage)->shouldBeCalled();

        $contextProphecy = $this->prophesize(Context::class);
        $contextProphecy->createTopic($topicName)->shouldBeCalled()->willReturn($topic);
        $contextProphecy->createProducer()->shouldBeCalled()->willReturn($producerProphecy->reveal());
        $contextProphecy->createMessage('foo', array(), array())->shouldBeCalled()->willReturn($psrMessage);

        $contextManagerProphecy = $this->prophesize(ContextManager::class);
        $contextManagerProphecy->context()->shouldBeCalled()->willReturn($contextProphecy->reveal());

        $encoderProphecy = $this->prophesize(SerializerInterface::class);
        $encoderProphecy->encode($envelope)->shouldBeCalled()->willReturn(array('body' => 'foo'));

        $transport = $this->getTransport(
            $encoderProphecy->reveal(),
            $contextManagerProphecy->reveal(),
            array(
                'topic' => array('name' => $topicName),
                'queue' => array('name' => $queueName),
            ),
            false
        );

        $transport->send($envelope);
    }

    public function testSendMessageOnSpecificTopic()
    {
        $topicName = 'topic';
        $queueName = 'queue';
        $specificTopicName = 'specific-topic';
        $message = new \stdClass();
        $message->foo = 'bar';
        $envelope = (new Envelope($message))->with(new TransportConfiguration(array('topic' => $specificTopicName)));

        $psrMessageProphecy = $this->prophesize(Message::class);
        $psrMessage = $psrMessageProphecy->reveal();
        $topicProphecy = $this->prophesize(Topic::class);
        $topic = $topicProphecy->reveal();

        $producerProphecy = $this->prophesize(Producer::class);
        $producerProphecy->send($topic, $psrMessage)->shouldBeCalled();

        $contextProphecy = $this->prophesize(Context::class);
        $contextProphecy->createTopic($specificTopicName)->shouldBeCalled()->willReturn($topic);
        $contextProphecy->createProducer()->shouldBeCalled()->willReturn($producerProphecy->reveal());
        $contextProphecy->createMessage('foo', array(), array())->shouldBeCalled()->willReturn($psrMessage);

        $contextManagerProphecy = $this->prophesize(ContextManager::class);
        $contextManagerProphecy->context()->shouldBeCalled()->willReturn($contextProphecy->reveal());
        $contextManagerProphecy->ensureExists(array(
            'topic' => $specificTopicName,
            'topicOptions' => array('name' => $topicName),
            'queue' => $queueName,
            'queueOptions' => array('name' => $queueName),
        ))->shouldBeCalled();

        $encoderProphecy = $this->prophesize(SerializerInterface::class);
        $encoderProphecy->encode($envelope)->shouldBeCalled()->willReturn(array('body' => 'foo'));

        $transport = $this->getTransport(
            $encoderProphecy->reveal(),
            $contextManagerProphecy->reveal(),
            array(
                'topic' => array('name' => $topicName),
                'queue' => array('name' => $queueName),
            ),
            true
        );

        $transport->send($envelope);
    }

    public function testSendWithQueueAndTopicSpecificOptions()
    {
        $topicName = 'topic';
        $queueName = 'queue';
        $message = new \stdClass();
        $message->foo = 'bar';
        $envelope = new Envelope($message);

        $psrMessageProphecy = $this->prophesize(Message::class);
        $psrMessage = $psrMessageProphecy->reveal();
        $topicProphecy = $this->prophesize(Topic::class);
        $topic = $topicProphecy->reveal();

        $producerProphecy = $this->prophesize(Producer::class);
        $producerProphecy->send($topic, $psrMessage)->shouldBeCalled();

        $contextProphecy = $this->prophesize(Context::class);
        $contextProphecy->createTopic($topicName)->shouldBeCalled()->willReturn($topic);
        $contextProphecy->createProducer()->shouldBeCalled()->willReturn($producerProphecy->reveal());
        $contextProphecy->createMessage('foo', array(), array())->shouldBeCalled()->willReturn($psrMessage);

        $contextManagerProphecy = $this->prophesize(ContextManager::class);
        $contextManagerProphecy->context()->shouldBeCalled()->willReturn($contextProphecy->reveal());
        $contextManagerProphecy->ensureExists(array(
            'topic' => $topicName,
            'topicOptions' => array('name' => $topicName, 'foo' => 'bar'),
            'queue' => $queueName,
            'queueOptions' => array('name' => $queueName, 'bar' => 'foo'),
        ))->shouldBeCalled();

        $encoderProphecy = $this->prophesize(SerializerInterface::class);
        $encoderProphecy->encode($envelope)->shouldBeCalled()->willReturn(array('body' => 'foo'));

        $transport = $this->getTransport(
            $encoderProphecy->reveal(),
            $contextManagerProphecy->reveal(),
            array(
                'topic' => array('name' => $topicName, 'foo' => 'bar'),
                'queue' => array('name' => $queueName, 'bar' => 'foo'),
            ),
            true
        );

        $transport->send($envelope);
    }

    public function testSendWithMessageMetadata()
    {
        $topicName = 'topic';
        $queueName = 'queue';
        $message = new \stdClass();
        $message->foo = 'bar';
        $envelope = (new Envelope($message))->with(new TransportConfiguration(array(
            'metadata' => array('routingKey' => 'foo.bar'),
        )));

        $psrMessageProphecy = $this->prophesize(DecoratedPsrMessage::class);
        $psrMessageProphecy->setRoutingKey('foo.bar')->shouldBeCalled();
        $psrMessage = $psrMessageProphecy->reveal();
        $topicProphecy = $this->prophesize(Topic::class);
        $topic = $topicProphecy->reveal();

        $producerProphecy = $this->prophesize(Producer::class);
        $producerProphecy->send($topic, $psrMessage)->shouldBeCalled();

        $contextProphecy = $this->prophesize(Context::class);
        $contextProphecy->createTopic($topicName)->shouldBeCalled()->willReturn($topic);
        $contextProphecy->createProducer()->shouldBeCalled()->willReturn($producerProphecy->reveal());
        $contextProphecy->createMessage('foo', array(), array())->shouldBeCalled()->willReturn($psrMessage);

        $contextManagerProphecy = $this->prophesize(ContextManager::class);
        $contextManagerProphecy->context()->shouldBeCalled()->willReturn($contextProphecy->reveal());
        $contextManagerProphecy->ensureExists(array(
            'topic' => $topicName,
            'topicOptions' => array('name' => $topicName, 'foo' => 'bar'),
            'queue' => $queueName,
            'queueOptions' => array('name' => $queueName, 'bar' => 'foo'),
        ))->shouldBeCalled();

        $encoderProphecy = $this->prophesize(SerializerInterface::class);
        $encoderProphecy->encode($envelope)->shouldBeCalled()->willReturn(array('body' => 'foo'));

        $transport = $this->getTransport(
            $encoderProphecy->reveal(),
            $contextManagerProphecy->reveal(),
            array(
                'topic' => array('name' => $topicName, 'foo' => 'bar'),
                'queue' => array('name' => $queueName, 'bar' => 'foo'),
            ),
            true
        );

        $transport->send($envelope);
    }

    public function testSendWithBadMessageMetadata()
    {
        $this->expectException(MissingMessageMetadataSetterException::class);
        $this->expectExceptionMessageRegExp('/Missing "setDumb" setter for "dumb" metadata key in "Double\\\Enqueue\\\MessengerAdapter\\\Tests\\\Fixtures\\\DecoratedPsrMessage\\\[^"]+" class/');

        $topicName = 'topic';
        $queueName = 'queue';
        $message = new \stdClass();
        $message->foo = 'bar';
        $envelope = (new Envelope($message))->with(new TransportConfiguration(array(
            'metadata' => array('dumb' => 'foo.bar'),
        )));

        $psrMessageProphecy = $this->prophesize(DecoratedPsrMessage::class);
        $psrMessage = $psrMessageProphecy->reveal();
        $topicProphecy = $this->prophesize(Topic::class);
        $topic = $topicProphecy->reveal();

        $contextProphecy = $this->prophesize(Context::class);
        $contextProphecy->createTopic($topicName)->shouldBeCalled()->willReturn($topic);
        $contextProphecy->createMessage('foo', array(), array())->shouldBeCalled()->willReturn($psrMessage);

        $contextManagerProphecy = $this->prophesize(ContextManager::class);
        $contextManagerProphecy->context()->shouldBeCalled()->willReturn($contextProphecy->reveal());
        $contextManagerProphecy->ensureExists(array(
            'topic' => $topicName,
            'topicOptions' => array('name' => $topicName, 'foo' => 'bar'),
            'queue' => $queueName,
            'queueOptions' => array('name' => $queueName, 'bar' => 'foo'),
        ))->shouldBeCalled();

        $encoderProphecy = $this->prophesize(SerializerInterface::class);
        $encoderProphecy->encode($envelope)->shouldBeCalled()->willReturn(array('body' => 'foo'));

        $transport = $this->getTransport(
            $encoderProphecy->reveal(),
            $contextManagerProphecy->reveal(),
            array(
                'topic' => array('name' => $topicName, 'foo' => 'bar'),
                'queue' => array('name' => $queueName, 'bar' => 'foo'),
            ),
            true
        );

        $transport->send($envelope);
    }

    /**
     * @expectedException \Enqueue\MessengerAdapter\Exception\SendingMessageFailedException
     */
    public function testThrow()
    {
        $topicName = 'topic';
        $queueName = 'queue';
        $message = new \stdClass();
        $message->foo = 'bar';
        $envelope = new Envelope($message);

        $psrMessageProphecy = $this->prophesize(Message::class);
        $psrMessage = $psrMessageProphecy->reveal();
        $topicProphecy = $this->prophesize(Topic::class);
        $topic = $topicProphecy->reveal();

        $exception = new Exception();

        $producerProphecy = $this->prophesize(Producer::class);
        $producerProphecy->send($topic, $psrMessage)->shouldBeCalled()->willThrow($exception);

        $contextProphecy = $this->prophesize(Context::class);
        $contextProphecy->createTopic($topicName)->shouldBeCalled()->willReturn($topic);
        $contextProphecy->createProducer()->shouldBeCalled()->willReturn($producerProphecy->reveal());
        $contextProphecy->createMessage('foo', array(), array())->shouldBeCalled()->willReturn($psrMessage);

        $contextManagerProphecy = $this->prophesize(ContextManager::class);
        $contextManagerProphecy->context()->shouldBeCalled()->willReturn($contextProphecy->reveal());
        $contextManagerProphecy->recoverException($exception, array(
            'topic' => $topicName,
            'topicOptions' => array('name' => $topicName),
            'queue' => $queueName,
            'queueOptions' => array('name' => $queueName),
        ))->shouldBeCalled()->willReturn(false);

        $encoderProphecy = $this->prophesize(SerializerInterface::class);
        $encoderProphecy->encode($envelope)->shouldBeCalled()->willReturn(array('body' => 'foo'));

        $transport = $this->getTransport(
            $encoderProphecy->reveal(),
            $contextManagerProphecy->reveal(),
            array(
                'topic' => array('name' => $topicName),
                'queue' => array('name' => $queueName),
            ),
            false
        );

        $transport->send($envelope);
    }

    public function testNullHandler()
    {
        $psrConsumerProphecy = $this->prophesize(Consumer::class);
        $psrConsumerProphecy->receive(30000)->shouldBeCalled()->willReturn(null);

        $psrQueueProphecy = $this->prophesize(Queue::class);
        $psrQueue = $psrQueueProphecy->reveal();

        $contextProphecy = $this->prophesize(Context::class);
        $contextProphecy->createQueue('messages')->shouldBeCalled()->willReturn($psrQueue);
        $contextProphecy->createConsumer($psrQueue)->shouldBeCalled()->willReturn($psrConsumerProphecy->reveal());

        $contextManagerProphecy = $this->prophesize(ContextManager::class);
        $contextManagerProphecy->context()->shouldBeCalled()->willReturn($contextProphecy->reveal());

        $transport = $this->getTransport(null, $contextManagerProphecy->reveal());
        $handlerArgument = 'not-null';
        $handler = function ($argument) use (&$handlerArgument, $transport) {
            $handlerArgument = $argument;
            $transport->stop();
        };
        $transport->receive($handler);
        $this->assertNull($handlerArgument);
    }

    private function getTransport(
        SerializerInterface $serializer = null,
        ContextManager $contextManager = null,
        array $options = array(),
        $debug = false
    ) {
        return new QueueInteropTransport(
            $serializer ?: $this->prophesize(SerializerInterface::class)->reveal(),
            $contextManager ?: $this->prophesize(ContextManager::class)->reveal(),
            $options,
            $debug
        );
    }
}

interface ProducerWithDelay extends Producer, DelayStrategyAware
{
}
