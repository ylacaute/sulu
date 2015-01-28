<?php
/*
 * This file is part of the Sulu CMS.
 *
 * (c) MASSIVE ART WebServices GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Bundle\ContentBundle\Preview;

use Doctrine\Bundle\DoctrineBundle\Registry;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DBALException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ratchet\ConnectionInterface;
use Sulu\Component\Content\Mapper\ContentMapperInterface;
use Sulu\Component\Websocket\Exception\MissingParameterException;
use Sulu\Component\Websocket\MessageDispatcher\MessageHandlerContext;
use Sulu\Component\Websocket\MessageDispatcher\MessageHandlerInterface;
use Sulu\Component\Webspace\Analyzer\AdminRequestAnalyzer;
use Sulu\Component\Webspace\Analyzer\RequestAnalyzerInterface;

class PreviewMessageHandler implements MessageHandlerInterface
{
    /**
     * @var PreviewInterface
     */
    private $preview;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var RequestAnalyzerInterface
     */
    private $requestAnalyzer;

    /**
     * @var Registry
     */
    private $registry;

    /**
     * @var ContentMapperInterface
     */
    private $contentMapper;

    /**
     * {@inheritdoc}
     */
    protected $name = 'sulu_content.preview';

    public function __construct(
        PreviewInterface $preview,
        AdminRequestAnalyzer $requestAnalyzer,
        Registry $registry,
        ContentMapperInterface $contentMapper,
        LoggerInterface $logger
    ) {
        $this->preview = $preview;
        $this->logger = $logger;
        $this->requestAnalyzer = $requestAnalyzer;
        $this->registry = $registry;
        $this->contentMapper = $contentMapper;
    }

    /**
     * {@inheritdoc}
     */
    public function handle(ConnectionInterface $conn, array $message, MessageHandlerContext $context)
    {
        // reconnect mysql
        $this->reconnect();

        try {
            return $this->execute($conn, $context, $message);
        } catch (\Exception $e) {
            // send fail message
            $conn->send(
                json_encode(
                    array(
                        'command' => 'fail',
                        'code' => $e->getCode(),
                        'msg' => $e->getMessage(),
                        'parentMsg' => $message
                    )
                )
            );
        }
    }

    /**
     * Executes command
     * @param ConnectionInterface $conn
     * @param MessageHandlerContext $context
     * @param array $msg
     * @return mixed|null
     * @throws ContextParametersNotFoundException
     * @throws MissingParameterException
     */
    private function execute(ConnectionInterface $conn, MessageHandlerContext $context, $msg)
    {
        if (!array_key_exists('command', $msg)) {
            throw new MissingParameterException('command');
        }
        $command = $msg['command'];
        $result = null;

        switch ($command) {
            case 'start':
                $result = $this->start($conn, $context, $msg);
                break;
            case 'stop':
                $result = $this->stop($conn, $context);
                break;
            case 'update':
                $result = $this->update($conn, $context, $msg);
                break;
        }

        return $result;
    }

    /**
     * Reconnect to mysql
     */
    private function reconnect()
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $this->registry->getManager();
        /** @var Connection $connection */
        $connection = $entityManager->getConnection();

        try {
            $connection->executeQuery('SELECT 1;');
        } catch (DBALException $exc) {
            $this->logger->warning('Mysql reconnect');
            $connection->close();
            $connection->connect();
        }
    }

    /**
     * Start preview session
     * @param ConnectionInterface $conn
     * @param MessageHandlerContext $context
     * @param array $msg
     * @return array
     * @throws MissingParameterException
     */
    private function start(ConnectionInterface $conn, MessageHandlerContext $context, $msg)
    {
        // init session

        // locale
        if (!array_key_exists('locale', $msg)) {
            throw new MissingParameterException('locale');
        }
        $locale = $msg['locale'];
        $context->set('locale', $locale);

        // webspace key
        if (!array_key_exists('webspaceKey', $msg)) {
            throw new MissingParameterException('webspaceKey');
        }
        $webspaceKey = $msg['webspaceKey'];
        $context->set('webspaceKey', $webspaceKey);

        // user id
        if (!array_key_exists('user', $msg)) {
            throw new MissingParameterException('user');
        }
        $user = $msg['user'];
        $context->set('user', $user);

        // content uuid
        if (!array_key_exists('content', $msg)) {
            throw new MissingParameterException('content');
        }
        $contentUuid = $msg['content'];
        // filter index page
        if ($contentUuid === 'index') {
            $startPage = $this->contentMapper->loadStartPage($webspaceKey, $locale);
            $contentUuid = $startPage->getUuid();
        }
        $context->set('content', $contentUuid);

        // init message vars
        $template = array_key_exists('template', $msg) ? $msg['template'] : null;
        $data = array_key_exists('data', $msg) ? $msg['data'] : null;

        // start preview
        $this->preview->start($user, $contentUuid, $webspaceKey, $locale, $data, $template);

        return array(
            'command' => 'start',
            'content' => $contentUuid,
            'msg' => 'OK'
        );
    }

    /**
     * Stop preview session
     * @param ConnectionInterface $from
     * @param MessageHandlerContext $context
     * @return array
     * @throws ContextParametersNotFoundException
     */
    private function stop(ConnectionInterface $from, MessageHandlerContext $context)
    {
        // check context parameters
        if (!$context->has('user')) {
            throw new ContextParametersNotFoundException();
        }

        // get user id
        $user = $context->get('user');

        // get session vars
        $contentUuid = $context->get('content');
        $locale = $context->get('locale');
        $webspaceKey = $context->get('webspaceKey');

        // stop preview
        $this->preview->stop($user, $contentUuid, $webspaceKey, $locale);

        $context->clear();

        return array(
            'command' => 'start',
            'content' => $contentUuid,
            'msg' => 'OK'
        );
    }

    /**
     * Updates properties of current session content
     * @param ConnectionInterface $from
     * @param MessageHandlerContext $context
     * @param array $msg
     * @return array
     * @throws ContextParametersNotFoundException
     * @throws MissingParameterException
     */
    private function update(ConnectionInterface $from, MessageHandlerContext $context, $msg)
    {
        // check context parameters
        if (
            !$context->has('content') &&
            !$context->has('locale') &&
            !$context->has('webspaceKey') &&
            !$context->has('user')
        ) {
            throw new ContextParametersNotFoundException();
        }

        // get user id
        $user = $context->get('user');

        // get session vars
        $contentUuid = $context->get('content');
        $locale = $context->get('locale');
        $webspaceKey = $context->get('webspaceKey');

        // init msg vars
        if (!array_key_exists('data', $msg) && is_array($msg['data'])) {
            throw new MissingParameterException('data');
        }
        $changes = $msg['data'];

        $this->requestAnalyzer->setWebspaceKey($webspaceKey);
        $this->requestAnalyzer->setLocalizationCode($locale);

        foreach ($changes as $property => $data) {
            // update property
            $this->preview->updateProperty(
                $user,
                $contentUuid,
                $webspaceKey,
                $locale,
                $property,
                $data
            );
        }

        return array(
            'command' => 'update',
            'content' => $contentUuid,
            'data' => $this->preview->getChanges(
                $user,
                $contentUuid,
                $webspaceKey,
                $locale
            )
        );
    }
}
