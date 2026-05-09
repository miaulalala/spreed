<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\Controller;

use OCA\Talk\Controller\BotConversationController;
use OCA\Talk\Exceptions\ParticipantNotFoundException;
use OCA\Talk\Exceptions\RoomNotFoundException;
use OCA\Talk\Manager;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Model\Bot;
use OCA\Talk\Model\BotServer;
use OCA\Talk\Model\BotServerMapper;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCA\Talk\Service\ParticipantService;
use OCA\Talk\Service\RoomFormatter;
use OCA\Talk\Service\RoomService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class BotConversationControllerTest extends TestCase {
	protected BotServerMapper&MockObject $botServerMapper;
	protected Manager&MockObject $manager;
	protected RoomService&MockObject $roomService;
	protected ParticipantService&MockObject $participantService;
	protected RoomFormatter&MockObject $roomFormatter;
	protected IUserManager&MockObject $userManager;

	private function makeController(string $userId = 'user1'): BotConversationController {
		return new BotConversationController(
			'spreed',
			$this->createMock(IRequest::class),
			$this->botServerMapper,
			$this->manager,
			$this->roomService,
			$this->participantService,
			$this->roomFormatter,
			$this->userManager,
			$userId,
		);
	}

	protected function setUp(): void {
		parent::setUp();
		$this->botServerMapper = $this->createMock(BotServerMapper::class);
		$this->manager = $this->createMock(Manager::class);
		$this->roomService = $this->createMock(RoomService::class);
		$this->participantService = $this->createMock(ParticipantService::class);
		$this->roomFormatter = $this->createMock(RoomFormatter::class);
		$this->userManager = $this->createMock(IUserManager::class);
	}

	private function makeBotServer(int $id, string $name, string $urlHash, int $state = Bot::STATE_ENABLED): BotServer {
		$bot = new BotServer();
		$bot->setId($id);
		$bot->setName($name);
		$bot->setDescription('A test bot');
		$bot->setUrlHash($urlHash);
		$bot->setState($state);
		return $bot;
	}

	// ---- listBots ----

	public function testListBotsReturnsOnlyEnabled(): void {
		$enabled = $this->makeBotServer(1, 'EnabledBot', 'abc123', Bot::STATE_ENABLED);
		$disabled = $this->makeBotServer(2, 'DisabledBot', 'def456', Bot::STATE_DISABLED);
		$this->botServerMapper->method('getAllBots')->willReturn([$enabled, $disabled]);

		$response = $this->makeController()->listBots();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertCount(1, $data);
		$this->assertSame(1, $data[0]['id']);
		$this->assertSame('EnabledBot', $data[0]['name']);
	}

	public function testListBotsReturnsEmptyWhenNoneEnabled(): void {
		$disabled = $this->makeBotServer(1, 'DisabledBot', 'abc123', Bot::STATE_DISABLED);
		$this->botServerMapper->method('getAllBots')->willReturn([$disabled]);

		$response = $this->makeController()->listBots();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $response->getData());
	}

	// ---- createBotConversation ----

	public function testCreateBotConversationRequiresLoggedInUser(): void {
		$this->userManager->method('get')->with('')->willReturn(null);

		$controller = new BotConversationController(
			'spreed',
			$this->createMock(IRequest::class),
			$this->botServerMapper,
			$this->manager,
			$this->roomService,
			$this->participantService,
			$this->roomFormatter,
			$this->userManager,
			null,
		);

		$response = $controller->createBotConversation('bot-abc123');
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testCreateBotConversationRejectsInvalidActorIdPrefix(): void {
		$user = $this->createMock(IUser::class);
		$this->userManager->method('get')->with('user1')->willReturn($user);

		$response = $this->makeController()->createBotConversation('notabot-abc123');
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testCreateBotConversationReturnsNotFoundForUnknownBot(): void {
		$user = $this->createMock(IUser::class);
		$this->userManager->method('get')->willReturn($user);
		$this->botServerMapper->method('findByUrlHash')->willThrowException(new DoesNotExistException(''));

		$response = $this->makeController()->createBotConversation('bot-abc123');
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testCreateBotConversationReturnsNotFoundForDisabledBot(): void {
		$user = $this->createMock(IUser::class);
		$this->userManager->method('get')->willReturn($user);
		$botServer = $this->makeBotServer(1, 'Bot', 'abc123', Bot::STATE_DISABLED);
		$this->botServerMapper->method('findByUrlHash')->willReturn($botServer);

		$response = $this->makeController()->createBotConversation('bot-abc123');
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testCreateBotConversationReturns200ForExistingRoom(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('user1');
		$this->userManager->method('get')->willReturn($user);

		$botServer = $this->makeBotServer(1, 'Bot', 'abc123');
		$this->botServerMapper->method('findByUrlHash')->with('abc123')->willReturn($botServer);

		$room = $this->createMock(Room::class);
		$this->manager->method('getBotConversationRoom')
			->with('user1', Attendee::ACTOR_BOT_PREFIX . 'abc123')
			->willReturn($room);

		$participant = $this->createMock(Participant::class);
		$this->participantService->method('getParticipant')
			->with($room, 'user1', false)
			->willReturn($participant);

		$this->roomFormatter->method('formatRoom')->willReturn(['token' => 'abc']);

		$response = $this->makeController()->createBotConversation('bot-abc123');
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testCreateBotConversationReturns201ForNewRoom(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('user1');
		$this->userManager->method('get')->willReturn($user);

		$botServer = $this->makeBotServer(1, 'Bot', 'abc123');
		$this->botServerMapper->method('findByUrlHash')->willReturn($botServer);

		$this->manager->method('getBotConversationRoom')->willThrowException(new RoomNotFoundException());

		$room = $this->createMock(Room::class);
		$this->roomService->method('createBotConversation')
			->with($user, $botServer)
			->willReturn($room);

		$participant = $this->createMock(Participant::class);
		$this->participantService->method('getParticipant')->willReturn($participant);

		$this->roomFormatter->method('formatRoom')->willReturn(['token' => 'xyz']);

		$response = $this->makeController()->createBotConversation('bot-abc123');
		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testCreateBotConversationReturns404IfParticipantMissing(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('user1');
		$this->userManager->method('get')->willReturn($user);

		$botServer = $this->makeBotServer(1, 'Bot', 'abc123');
		$this->botServerMapper->method('findByUrlHash')->willReturn($botServer);

		$room = $this->createMock(Room::class);
		$this->manager->method('getBotConversationRoom')->willReturn($room);

		$this->participantService->method('getParticipant')
			->willThrowException(new ParticipantNotFoundException());

		$response = $this->makeController()->createBotConversation('bot-abc123');
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testCreateBotConversationExtractsUrlHashFromActorId(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('user1');
		$this->userManager->method('get')->willReturn($user);

		// Verify the correct hash is extracted from the actor ID
		$this->botServerMapper->expects($this->once())
			->method('findByUrlHash')
			->with('deadbeef1234')
			->willThrowException(new DoesNotExistException(''));

		$this->makeController()->createBotConversation('bot-deadbeef1234');
	}
}
