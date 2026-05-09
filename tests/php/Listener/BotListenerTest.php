<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\Listener;

use OCA\Talk\Db\BotConversationMapper;
use OCA\Talk\Db\BotServerMapper;
use OCA\Talk\Events\BotDisabledEvent;
use OCA\Talk\Events\BotEnabledEvent;
use OCA\Talk\Events\BotInstallEvent;
use OCA\Talk\Events\BotUninstallEvent;
use OCA\Talk\Listener\BotListener;
use OCA\Talk\Model\Bot;
use OCA\Talk\Model\BotServer;
use OCA\Talk\Room;
use OCA\Talk\Service\BotService;
use OCA\Talk\Service\ParticipantService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

#[Group('DB')]
class BotListenerTest extends TestCase {
	protected BotServerMapper&MockObject $botServerMapper;
	protected BotConversationMapper&MockObject $botConversationMapper;
	protected BotService&MockObject $botService;
	protected ParticipantService&MockObject $participantService;
	protected LoggerInterface&MockObject $logger;
	protected ?BotListener $listener = null;

	public function setUp(): void {
		parent::setUp();

		$this->botServerMapper = $this->createMock(BotServerMapper::class);
		$this->botConversationMapper = $this->createMock(BotConversationMapper::class);
		$this->botService = $this->createMock(BotService::class);
		$this->participantService = $this->createMock(ParticipantService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->listener = new BotListener(
			$this->botServerMapper,
			$this->botConversationMapper,
			$this->botService,
			$this->participantService,
			$this->logger,
		);
	}

	protected function makeBotServer(int $id, string $name, string $url, string $secret, int $state, int $features): BotServer {
		$botServer = new BotServer();
		$botServer->setId($id);
		$botServer->setName($name);
		$botServer->setUrl($url);
		$botServer->setUrlHash(sha1($url));
		$botServer->setState($state);
		$botServer->setFeatures($features);
		return $botServer;
	}

	public function testHandleRoutesBotEnabledEventToAfterBotEnabled(): void {
		$room = $this->createMock(Room::class);
		$botServer = $this->makeBotServer(1, 'TestBot', 'https://bot.example.com', 'secret', Bot::STATE_ENABLED, Bot::FEATURE_WEBHOOK);

		$event = new BotEnabledEvent($room, $botServer);

		$this->botService->expects($this->once())
			->method('afterBotEnabled')
			->with($event);

		$this->listener->handle($event);
	}

	public function testHandleRoutesBotDisabledEventToAfterBotDisabled(): void {
		$room = $this->createMock(Room::class);
		$botServer = $this->makeBotServer(1, 'TestBot', 'https://bot.example.com', 'secret', Bot::STATE_ENABLED, Bot::FEATURE_WEBHOOK);

		$event = new BotDisabledEvent($room, $botServer);

		$this->botService->expects($this->once())
			->method('afterBotDisabled')
			->with($event);

		$this->listener->handle($event);
	}

	public function testHandleUninstallHappyPathRemovesParticipantsAndDeletes(): void {
		$url = 'https://bot.example.com';
		$secret = 'validSecret1234567890validSecret1234567890';

		$event = new BotUninstallEvent($secret, $url);

		$botServer = $this->makeBotServer(42, 'TestBot', $url, $secret, Bot::STATE_ENABLED, Bot::FEATURE_WEBHOOK);

		$this->botServerMapper->expects($this->once())
			->method('findByUrlAndSecret')
			->with($url, $secret)
			->willReturn($botServer);

		$this->participantService->expects($this->once())
			->method('removeAllBotParticipants')
			->with($botServer);

		$this->botConversationMapper->expects($this->once())
			->method('deleteByBotId')
			->with(42);

		$this->botServerMapper->expects($this->once())
			->method('delete')
			->with($botServer);

		$this->listener->handle($event);
	}

	public function testHandleUninstallDoesNothingWhenBotNotFound(): void {
		$url = 'https://nonexistent.example.com';
		$secret = 'validSecret1234567890validSecret1234567890';

		$event = new BotUninstallEvent($secret, $url);

		$this->botServerMapper->expects($this->once())
			->method('findByUrlAndSecret')
			->with($url, $secret)
			->willThrowException(new DoesNotExistException('Bot not found'));

		$this->participantService->expects($this->never())
			->method('removeAllBotParticipants');

		$this->botConversationMapper->expects($this->never())
			->method('deleteByBotId');

		$this->botServerMapper->expects($this->never())
			->method('delete');

		$this->listener->handle($event);
	}

	public function testHandleInstallCreatesNewBot(): void {
		$name = 'NewBot';
		$secret = 'validSecret1234567890validSecret1234567890';
		$url = 'https://newbot.example.com';
		$description = 'A new test bot';

		$event = new BotInstallEvent($name, $secret, $url, $description);

		$this->botService->expects($this->once())
			->method('validateBotParameters')
			->with($name, $secret, $url, $description);

		$this->botServerMapper->expects($this->once())
			->method('findByUrlAndSecret')
			->with($url, $secret)
			->willThrowException(new DoesNotExistException('Not found'));

		$this->botServerMapper->expects($this->once())
			->method('insert')
			->with($this->isInstanceOf(BotServer::class));

		$this->listener->handle($event);
	}

	public function testHandleInstallUpdatesExistingBot(): void {
		$name = 'UpdatedBot';
		$secret = 'validSecret1234567890validSecret1234567890';
		$url = 'https://existing.example.com';
		$description = 'Updated description';

		$event = new BotInstallEvent($name, $secret, $url, $description);

		$existingBot = $this->makeBotServer(10, 'OldBot', $url, $secret, Bot::STATE_ENABLED, Bot::FEATURE_WEBHOOK);

		$this->botService->expects($this->once())
			->method('validateBotParameters')
			->with($name, $secret, $url, $description);

		$this->botServerMapper->expects($this->once())
			->method('findByUrlAndSecret')
			->with($url, $secret)
			->willReturn($existingBot);

		$this->botServerMapper->expects($this->once())
			->method('update')
			->with($existingBot);

		$this->listener->handle($event);
	}

	public function testHandleInstallThrowsOnUrlConflictWithDifferentSecret(): void {
		$name = 'ConflictBot';
		$secret = 'differentSecret1234567890validSecret12345';
		$url = 'https://conflict.example.com';
		$description = '';

		$event = new BotInstallEvent($name, $secret, $url, $description);

		$this->botService->expects($this->once())
			->method('validateBotParameters')
			->with($name, $secret, $url, $description);

		// The mapper throws when the URL exists but secret doesn't match —
		// in practice the install handler detects this as a conflict
		$this->botServerMapper->expects($this->once())
			->method('findByUrlAndSecret')
			->with($url, $secret)
			->willThrowException(new \InvalidArgumentException('URL conflict'));

		$this->logger->expects($this->once())
			->method('error');

		$this->expectException(\InvalidArgumentException::class);

		$this->listener->handle($event);
	}

	public function testHandleInstallWithInvalidParametersLogsAndRethrows(): void {
		$name = '';
		$secret = 'tooshort';
		$url = 'not-a-url';
		$description = '';

		$event = new BotInstallEvent($name, $secret, $url, $description);

		$this->botService->expects($this->once())
			->method('validateBotParameters')
			->with($name, $secret, $url, $description)
			->willThrowException(new \InvalidArgumentException('Invalid parameters'));

		$this->logger->expects($this->once())
			->method('error');

		$this->botServerMapper->expects($this->never())
			->method('findByUrlAndSecret');

		$this->expectException(\InvalidArgumentException::class);

		$this->listener->handle($event);
	}
}
