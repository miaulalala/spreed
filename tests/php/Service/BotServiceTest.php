<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\Service;

use OCA\Talk\Chat\ChatManager;
use OCA\Talk\Events\BotDisabledEvent;
use OCA\Talk\Events\BotEnabledEvent;
use OCA\Talk\Model\Bot;
use OCA\Talk\Model\BotConversation;
use OCA\Talk\Model\BotConversationMapper;
use OCA\Talk\Model\BotServer;
use OCA\Talk\Model\BotServerMapper;
use OCA\Talk\Room;
use OCA\Talk\Service\BotService;
use OCA\Talk\Service\ParticipantService;
use OCA\Talk\Service\ThreadService;
use OCA\Talk\TalkSession;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\Security\ICertificateManager;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

#[Group('DB')]
class BotServiceTest extends TestCase {
	protected BotServerMapper&MockObject $botServerMapper;
	protected BotConversationMapper&MockObject $botConversationMapper;
	protected ThreadService&MockObject $threadService;
	protected ChatManager&MockObject $chatManager;
	protected ParticipantService&MockObject $participantService;
	protected IClientService&MockObject $clientService;
	protected IConfig&MockObject $iConfig;
	protected IUserSession&MockObject $userSession;
	protected TalkSession&MockObject $talkSession;
	protected ISession&MockObject $session;
	protected ISecureRandom&MockObject $secureRandom;
	protected IURLGenerator&MockObject $urlGenerator;
	protected IFactory&MockObject $l10nFactory;
	protected ITimeFactory&MockObject $timeFactory;
	protected LoggerInterface&MockObject $logger;
	protected ICertificateManager&MockObject $certificateManager;
	protected IEventDispatcher&MockObject $dispatcher;
	protected IAppManager&MockObject $appManager;
	protected ?BotService $service = null;

	public function setUp(): void {
		parent::setUp();

		$this->botServerMapper = $this->createMock(BotServerMapper::class);
		$this->botConversationMapper = $this->createMock(BotConversationMapper::class);
		$this->threadService = $this->createMock(ThreadService::class);
		$this->chatManager = $this->createMock(ChatManager::class);
		$this->participantService = $this->createMock(ParticipantService::class);
		$this->clientService = $this->createMock(IClientService::class);
		$this->iConfig = $this->createMock(IConfig::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->talkSession = $this->createMock(TalkSession::class);
		$this->session = $this->createMock(ISession::class);
		$this->secureRandom = $this->createMock(ISecureRandom::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->l10nFactory = $this->createMock(IFactory::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->certificateManager = $this->createMock(ICertificateManager::class);
		$this->dispatcher = $this->createMock(IEventDispatcher::class);
		$this->appManager = $this->createMock(IAppManager::class);

		$this->timeFactory->method('getDateTime')
			->willReturn(new \DateTime());

		$this->service = new BotService(
			$this->botServerMapper,
			$this->botConversationMapper,
			$this->threadService,
			$this->chatManager,
			$this->participantService,
			$this->clientService,
			$this->iConfig,
			$this->userSession,
			$this->talkSession,
			$this->session,
			$this->secureRandom,
			$this->urlGenerator,
			$this->l10nFactory,
			$this->timeFactory,
			$this->logger,
			$this->certificateManager,
			$this->dispatcher,
			$this->appManager,
		);
	}

	protected function makeBotServer(int $id, string $name, string $url, int $state, int $features): BotServer {
		$botServer = new BotServer();
		$botServer->setId($id);
		$botServer->setName($name);
		$botServer->setUrl($url);
		$botServer->setUrlHash(sha1($url));
		$botServer->setState($state);
		$botServer->setFeatures($features);
		return $botServer;
	}

	protected function makeBotConversation(int $botId, string $token): BotConversation {
		$botConversation = new BotConversation();
		$botConversation->setBotId($botId);
		$botConversation->setToken($token);
		return $botConversation;
	}

	public function testAfterBotEnabledCallsAddBotParticipantAndDispatches(): void {
		$room = $this->createMock(Room::class);
		$botServer = $this->makeBotServer(1, 'TestBot', 'https://bot.example.com', Bot::STATE_ENABLED, Bot::FEATURE_EVENT);

		$event = new BotEnabledEvent($room, $botServer);

		$this->participantService->expects($this->once())
			->method('addBotParticipant')
			->with($room, $botServer);

		// With FEATURE_EVENT, invokeBots will dispatch a BotInvokeEvent
		$this->dispatcher->expects($this->atLeastOnce())
			->method('dispatchTyped');

		$this->service->afterBotEnabled($event);
	}

	public function testAfterBotDisabledCallsRemoveBotParticipantAndDispatches(): void {
		$room = $this->createMock(Room::class);
		$botServer = $this->makeBotServer(2, 'TestBot', 'https://bot.example.com', Bot::STATE_ENABLED, Bot::FEATURE_EVENT);

		$event = new BotDisabledEvent($room, $botServer);

		$this->participantService->expects($this->once())
			->method('removeBotParticipant')
			->with($room, $botServer);

		// With FEATURE_EVENT, invokeBots will dispatch a BotInvokeEvent
		$this->dispatcher->expects($this->atLeastOnce())
			->method('dispatchTyped');

		$this->service->afterBotDisabled($event);
	}

	public function testGetBotsForTokenReturnsOnlyEnabledBots(): void {
		$token = 'token123';

		$conv1 = $this->makeBotConversation(1, $token);
		$conv2 = $this->makeBotConversation(2, $token);

		$enabledServer = $this->makeBotServer(1, 'EnabledBot', 'https://enabled.example.com', Bot::STATE_ENABLED, Bot::FEATURE_WEBHOOK);
		$disabledServer = $this->makeBotServer(2, 'DisabledBot', 'https://disabled.example.com', Bot::STATE_DISABLED, Bot::FEATURE_WEBHOOK);

		$this->botConversationMapper->expects($this->once())
			->method('findForToken')
			->with($token)
			->willReturn([$conv1, $conv2]);

		$this->botServerMapper->expects($this->once())
			->method('findByIds')
			->with([1, 2])
			->willReturn([$enabledServer, $disabledServer]);

		$bots = $this->service->getBotsForToken($token);

		$this->assertCount(1, $bots);
		$this->assertInstanceOf(Bot::class, $bots[0]);
		$this->assertTrue($bots[0]->isEnabled());
	}

	public function testGetBotsForTokenFiltersByFeatureFlag(): void {
		$token = 'token456';

		$conv1 = $this->makeBotConversation(1, $token);
		$conv2 = $this->makeBotConversation(2, $token);

		$webhookServer = $this->makeBotServer(1, 'WebhookBot', 'https://webhook.example.com', Bot::STATE_ENABLED, Bot::FEATURE_WEBHOOK);
		$eventServer = $this->makeBotServer(2, 'EventBot', 'https://event.example.com', Bot::STATE_ENABLED, Bot::FEATURE_EVENT);

		$this->botConversationMapper->expects($this->once())
			->method('findForToken')
			->with($token)
			->willReturn([$conv1, $conv2]);

		$this->botServerMapper->expects($this->once())
			->method('findByIds')
			->with([1, 2])
			->willReturn([$webhookServer, $eventServer]);

		// Only bots with FEATURE_EVENT should be returned
		$bots = $this->service->getBotsForToken($token, Bot::FEATURE_EVENT);

		$this->assertCount(1, $bots);
		$this->assertSame('EventBot', $bots[0]->getBotServer()->getName());
	}

	public function testGetBotsForTokenHandlesMissingServerGracefully(): void {
		$token = 'token789';

		$conv1 = $this->makeBotConversation(1, $token);
		$conv2 = $this->makeBotConversation(2, $token);

		// Only one server returned — the other is missing
		$server1 = $this->makeBotServer(1, 'OnlyBot', 'https://only.example.com', Bot::STATE_ENABLED, Bot::FEATURE_WEBHOOK);

		$this->botConversationMapper->expects($this->once())
			->method('findForToken')
			->with($token)
			->willReturn([$conv1, $conv2]);

		$this->botServerMapper->expects($this->once())
			->method('findByIds')
			->with([1, 2])
			->willReturn([$server1]);

		$bots = $this->service->getBotsForToken($token);

		// Should only return the bot whose server exists
		$this->assertCount(1, $bots);
		$this->assertSame('OnlyBot', $bots[0]->getBotServer()->getName());
	}

	public function testGetBotsForTokenReturnsEmptyArrayWhenNoConversations(): void {
		$token = 'empty-token';

		$this->botConversationMapper->expects($this->once())
			->method('findForToken')
			->with($token)
			->willReturn([]);

		$this->botServerMapper->expects($this->never())
			->method('findByIds');

		$bots = $this->service->getBotsForToken($token);

		$this->assertSame([], $bots);
	}

	public static function dataValidateBotParametersInvalidName(): array {
		return [
			'empty name' => ['', 'validSecret1234567890validSecret1234567890', 'https://bot.example.com', ''],
			'name too long' => [str_repeat('a', 65), 'validSecret1234567890validSecret1234567890', 'https://bot.example.com', ''],
		];
	}

	#[DataProvider('dataValidateBotParametersInvalidName')]
	public function testValidateBotParametersInvalidNameThrows(string $name, string $secret, string $url, string $description): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service->validateBotParameters($name, $secret, $url, $description);
	}

	public static function dataValidateBotParametersInvalidSecret(): array {
		return [
			'secret too short' => ['ValidName', str_repeat('a', 39), 'https://bot.example.com', ''],
			'secret too long' => ['ValidName', str_repeat('a', 129), 'https://bot.example.com', ''],
		];
	}

	#[DataProvider('dataValidateBotParametersInvalidSecret')]
	public function testValidateBotParametersInvalidSecretThrows(string $name, string $secret, string $url, string $description): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service->validateBotParameters($name, $secret, $url, $description);
	}

	public static function dataValidateBotParametersInvalidUrl(): array {
		return [
			'no scheme' => ['ValidName', 'validSecret1234567890validSecret1234567890', 'bot.example.com', ''],
			'ftp scheme' => ['ValidName', 'validSecret1234567890validSecret1234567890', 'ftp://bot.example.com', ''],
			'empty url' => ['ValidName', 'validSecret1234567890validSecret1234567890', '', ''],
		];
	}

	#[DataProvider('dataValidateBotParametersInvalidUrl')]
	public function testValidateBotParametersInvalidUrlThrows(string $name, string $secret, string $url, string $description): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service->validateBotParameters($name, $secret, $url, $description);
	}

	public function testValidateBotParametersDescriptionTooLongThrows(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service->validateBotParameters(
			'ValidName',
			'validSecret1234567890validSecret1234567890',
			'https://bot.example.com',
			str_repeat('a', 4001),
		);
	}

	public static function dataValidateBotParametersValid(): array {
		return [
			'https url' => ['ValidName', 'validSecret1234567890validSecret1234567890', 'https://bot.example.com', ''],
			'http url' => ['ValidName', 'validSecret1234567890validSecret1234567890', 'http://bot.example.com', ''],
			'app prefix url' => ['ValidName', 'validSecret1234567890validSecret1234567890', Bot::URL_APP_PREFIX . 'myapp/path', ''],
			'response only prefix url' => ['ValidName', 'validSecret1234567890validSecret1234567890', Bot::URL_RESPONSE_ONLY_PREFIX . 'myapp', ''],
			'with description' => ['ValidName', 'validSecret1234567890validSecret1234567890', 'https://bot.example.com', 'A valid description'],
			'max name length' => [str_repeat('a', 64), 'validSecret1234567890validSecret1234567890', 'https://bot.example.com', ''],
			'max secret length' => ['ValidName', str_repeat('a', 128), 'https://bot.example.com', ''],
			'max description length' => ['ValidName', 'validSecret1234567890validSecret1234567890', 'https://bot.example.com', str_repeat('a', 4000)],
		];
	}

	#[DataProvider('dataValidateBotParametersValid')]
	public function testValidateBotParametersValidDoesNotThrow(string $name, string $secret, string $url, string $description): void {
		// Should not throw
		$this->service->validateBotParameters($name, $secret, $url, $description);
		$this->addToAssertionCount(1);
	}

	public function testIsAppForBotEnabledReturnsTrueForNonAppUrl(): void {
		$botServer = $this->makeBotServer(1, 'TestBot', 'https://bot.example.com', Bot::STATE_ENABLED, Bot::FEATURE_WEBHOOK);

		$this->appManager->expects($this->never())
			->method('isEnabledForAnyone');

		$result = $this->service->isAppForBotEnabled($botServer);

		$this->assertTrue($result);
	}

	public function testIsAppForBotEnabledReturnsTrueForHttpUrl(): void {
		$botServer = $this->makeBotServer(1, 'TestBot', 'http://bot.example.com', Bot::STATE_ENABLED, Bot::FEATURE_WEBHOOK);

		$this->appManager->expects($this->never())
			->method('isEnabledForAnyone');

		$result = $this->service->isAppForBotEnabled($botServer);

		$this->assertTrue($result);
	}

	public function testIsAppForBotEnabledDelegatesToAppManagerForAppUrl(): void {
		$appId = 'my_bot_app';
		$url = Bot::URL_APP_PREFIX . $appId . '/some/path';
		$botServer = $this->makeBotServer(1, 'AppBot', $url, Bot::STATE_ENABLED, Bot::FEATURE_EVENT);

		$this->appManager->expects($this->once())
			->method('isEnabledForAnyone')
			->with($appId)
			->willReturn(true);

		$result = $this->service->isAppForBotEnabled($botServer);

		$this->assertTrue($result);
	}

	public function testIsAppForBotEnabledReturnsFalseWhenAppDisabled(): void {
		$appId = 'my_disabled_app';
		$url = Bot::URL_APP_PREFIX . $appId . '/some/path';
		$botServer = $this->makeBotServer(1, 'AppBot', $url, Bot::STATE_ENABLED, Bot::FEATURE_EVENT);

		$this->appManager->expects($this->once())
			->method('isEnabledForAnyone')
			->with($appId)
			->willReturn(false);

		$result = $this->service->isAppForBotEnabled($botServer);

		$this->assertFalse($result);
	}
}
