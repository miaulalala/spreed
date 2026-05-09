<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Controller;

use OCA\Talk\Exceptions\ParticipantNotFoundException;
use OCA\Talk\Exceptions\RoomNotFoundException;
use OCA\Talk\Manager;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Model\Bot;
use OCA\Talk\Model\BotServerMapper;
use OCA\Talk\ResponseDefinitions;
use OCA\Talk\Service\ParticipantService;
use OCA\Talk\Service\RoomFormatter;
use OCA\Talk\Service\RoomService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;

/**
 * @psalm-import-type TalkRoom from ResponseDefinitions
 */
class BotConversationController extends AEnvironmentAwareOCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		protected BotServerMapper $botServerMapper,
		protected Manager $manager,
		protected RoomService $roomService,
		protected ParticipantService $participantService,
		protected RoomFormatter $roomFormatter,
		protected IUserManager $userManager,
		protected ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * List all globally enabled bots available for starting conversations
	 *
	 * @return DataResponse<Http::STATUS_OK, list<array{id: int, name: string, description: null|string}>, array{}>
	 *
	 * 200: List of enabled bots
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/{apiVersion}/bots', requirements: ['apiVersion' => '(v4)'])]
	public function listBots(): DataResponse {
		$bots = $this->botServerMapper->getAllBots();
		$result = [];
		foreach ($bots as $bot) {
			if ($bot->getState() === Bot::STATE_ENABLED) {
				$result[] = [
					'id' => $bot->getId(),
					'name' => $bot->getName(),
					'description' => $bot->getDescription(),
				];
			}
		}
		return new DataResponse($result);
	}

	/**
	 * Open or create a 1:1 conversation with a bot
	 *
	 * @param string $botActorId Actor ID of the bot (bot-{urlhash})
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED, TalkRoom, array{}>|DataResponse<Http::STATUS_NOT_FOUND, array{error: string}, array{}>
	 *
	 * 200: Existing bot conversation returned
	 * 201: New bot conversation created
	 * 404: Bot not found or not enabled
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/{apiVersion}/bots/{botActorId}/room', requirements: ['apiVersion' => '(v4)', 'botActorId' => 'bot-[0-9a-f]+'])]
	public function createBotConversation(string $botActorId): DataResponse {
		$currentUser = $this->userManager->get($this->userId);
		if (!$currentUser instanceof IUser) {
			return new DataResponse(['error' => 'bot'], Http::STATUS_NOT_FOUND);
		}

		if (!str_starts_with($botActorId, Attendee::ACTOR_BOT_PREFIX)) {
			return new DataResponse(['error' => 'bot'], Http::STATUS_NOT_FOUND);
		}
		$urlHash = substr($botActorId, strlen(Attendee::ACTOR_BOT_PREFIX));

		try {
			$botServer = $this->botServerMapper->findByUrlHash($urlHash);
		} catch (DoesNotExistException) {
			return new DataResponse(['error' => 'bot'], Http::STATUS_NOT_FOUND);
		}

		if ($botServer->getState() !== Bot::STATE_ENABLED) {
			return new DataResponse(['error' => 'bot'], Http::STATUS_NOT_FOUND);
		}

		try {
			$room = $this->manager->getBotConversationRoom($currentUser->getUID(), $botActorId);
			$isNew = false;
		} catch (RoomNotFoundException) {
			$room = $this->roomService->createBotConversation($currentUser, $botServer);
			$isNew = true;
		}

		try {
			$participant = $this->participantService->getParticipant($room, $currentUser->getUID(), false);
		} catch (ParticipantNotFoundException) {
			return new DataResponse(['error' => 'room'], Http::STATUS_NOT_FOUND);
		}

		$status = $isNew ? Http::STATUS_CREATED : Http::STATUS_OK;
		return new DataResponse(
			$this->roomFormatter->formatRoom($this->getResponseFormat(), [], $room, $participant),
			$status
		);
	}
}
