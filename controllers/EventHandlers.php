<?php

namespace Rhymix\Modules\Activitypub\Controllers;

use Rhymix\Modules\Activitypub\Models\Actor as ActorModel;
use Rhymix\Modules\Activitypub\Models\Config as ConfigModel;
use ActivityPhp\Type;
use ActivityPhp\Type\TypeConfiguration;

/**
 * ActivityPub 연동 모듈 - 이벤트 핸들러 (트리거)
 *
 * Copyright (c) Lastorder-DC
 * Licensed under GPLv2
 */
class EventHandlers extends Base
{
	/**
	 * document.publishDocument after 트리거
	 * 공개 게시물이 발행될 때 ActivityPub으로 알림
	 *
	 * @param object $obj
	 */
	public function afterPublishDocument($obj)
	{
		// 모듈이 비활성화된 경우 즉시 반환
		$config = ConfigModel::getConfig();
		if (($config->module_enabled ?? 'Y') !== 'Y')
		{
			return;
		}

		if (!$obj || !isset($obj->module_srl))
		{
			return;
		}

		// 공개 글만 처리
		$status = $obj->status ?? '';
		if ($status !== 'PUBLIC' && $status !== '')
		{
			return;
		}

		// 비로그인 상태로 접속 불가능한 게시판의 게시물은 AP로 발행하지 않음
		if (!ActorModel::isModulePubliclyAccessible($obj->module_srl))
		{
			return;
		}

		// 해당 게시물에 대한 모든 Actor 가져오기 (게시판 Actor + 유저 Actor)
		$member_srl = $obj->member_srl ?? 0;
		$actors = ActorModel::getActorsForDocument($obj->module_srl, $member_srl);
		if (empty($actors))
		{
			return;
		}

		// 카테고리 srl 추출
		$category_srl = intval($obj->category_srl ?? 0);

		// 각 Actor를 통해 팔로워에게 전송
		foreach ($actors as $actor)
		{
			// 카테고리 필터 확인
			if (($actor->category_filter_mode ?? 'off') === 'include')
			{
				$allowed = array_map('intval', array_filter(explode(',', $actor->category_filter_srls ?? ''), 'strlen'));
				if (!in_array($category_srl, $allowed))
				{
					continue;
				}
			}
			elseif (($actor->category_filter_mode ?? 'off') === 'exclude')
			{
				$excluded = array_map('intval', array_filter(explode(',', $actor->category_filter_srls ?? ''), 'strlen'));
				if (in_array($category_srl, $excluded))
				{
					continue;
				}
			}

			// AP 활동 기록 추가
			ActorModel::addActivity($actor->actor_srl, 'document', $obj->document_srl, $obj->module_srl, $member_srl);

			// 비동기 큐가 사용 가능한 경우 큐에 추가
			if (self::isQueueAvailable())
			{
				$args = new \stdClass;
				$args->actor_srl = $actor->actor_srl;
				$args->document_srl = $obj->document_srl;
				$args->module_srl = $obj->module_srl;
				$args->title = $obj->title ?? '';
				$args->content = $obj->content ?? '';
				$args->nick_name = $obj->nick_name ?? '';

				\Rhymix\Framework\Queue::addTask(
					'Rhymix\\Modules\\Activitypub\\Controllers\\EventHandlers::processDocumentDeliveryTask',
					$args
				);
			}
			else
			{
				self::deliverDocumentToFollowers($actor, $obj);
			}
		}
	}

	/**
	 * comment.insertComment after 트리거
	 * 댓글이 작성될 때 ActivityPub으로 알림
	 *
	 * @param object $obj
	 */
	public function afterInsertComment($obj)
	{
		// 모듈이 비활성화된 경우 즉시 반환
		$config = ConfigModel::getConfig();
		if (($config->module_enabled ?? 'Y') !== 'Y')
		{
			return;
		}

		if (!$obj || !isset($obj->module_srl))
		{
			return;
		}

		// 댓글 AP 전송이 비활성화된 경우 제외
		if (($config->send_comments ?? 'N') !== 'Y')
		{
			return;
		}

		// 비밀 댓글은 제외
		if (isset($obj->is_secret) && $obj->is_secret === 'Y')
		{
			return;
		}

		// 승인되지 않은 댓글 제외 (status 0 = 미승인)
		if (isset($obj->status) && intval($obj->status) === 0)
		{
			return;
		}

		// 비로그인 상태로 접속 불가능한 게시판의 댓글은 AP로 발행하지 않음
		if (!ActorModel::isModulePubliclyAccessible($obj->module_srl))
		{
			return;
		}

		// 해당 댓글에 대한 모든 Actor 가져오기
		$member_srl = $obj->member_srl ?? 0;
		$actors = ActorModel::getActorsForDocument($obj->module_srl, $member_srl);
		if (empty($actors))
		{
			return;
		}

		// 각 Actor를 통해 팔로워에게 전송
		foreach ($actors as $actor)
		{
			// AP 활동 기록 추가
			ActorModel::addActivity($actor->actor_srl, 'comment', $obj->comment_srl, $obj->module_srl, $member_srl);

			// 비동기 큐가 사용 가능한 경우 큐에 추가
			if (self::isQueueAvailable())
			{
				$args = new \stdClass;
				$args->actor_srl = $actor->actor_srl;
				$args->comment_srl = $obj->comment_srl;
				$args->document_srl = $obj->document_srl;
				$args->module_srl = $obj->module_srl;
				$args->content = $obj->content ?? '';
				$args->nick_name = $obj->nick_name ?? '';

				\Rhymix\Framework\Queue::addTask(
					'Rhymix\\Modules\\Activitypub\\Controllers\\EventHandlers::processCommentDeliveryTask',
					$args
				);
			}
			else
			{
				self::deliverCommentToFollowers($actor, $obj);
			}
		}
	}

	/**
	 * document.updateDocument after 트리거
	 * 게시물이 수정될 때 ActivityPub으로 Update 또는 Delete 알림
	 *
	 * @param object $obj
	 */
	public function afterUpdateDocument($obj)
	{
		// 모듈이 비활성화된 경우 즉시 반환
		$config = ConfigModel::getConfig();
		if (($config->module_enabled ?? 'Y') !== 'Y')
		{
			return;
		}

		if (!$obj || !isset($obj->document_srl))
		{
			return;
		}

		// 수정 후 상태가 PUBLIC이 아닌 경우 Delete 전송
		$status = $obj->status ?? '';
		if ($status !== 'PUBLIC' && $status !== '')
		{
			$this->sendDeleteDocumentActivity($obj);
			return;
		}

		// 비로그인 상태로 접속 불가능한 게시판의 게시물은 AP로 발행하지 않음
		$module_srl = $obj->module_srl ?? 0;
		if (!$module_srl || !ActorModel::isModulePubliclyAccessible($module_srl))
		{
			return;
		}

		// 해당 게시물에 대한 모든 Actor 가져오기 (게시판 Actor + 유저 Actor)
		$member_srl = $obj->member_srl ?? 0;
		$actors = ActorModel::getActorsForDocument($module_srl, $member_srl);
		if (empty($actors))
		{
			return;
		}

		// 각 Actor를 통해 팔로워에게 Update 전송
		$category_srl = intval($obj->category_srl ?? 0);
		foreach ($actors as $actor)
		{
			// 카테고리 필터 확인
			if (($actor->category_filter_mode ?? 'off') === 'include')
			{
				$allowed = array_map('intval', array_filter(explode(',', $actor->category_filter_srls ?? ''), 'strlen'));
				if (!in_array($category_srl, $allowed))
				{
					continue;
				}
			}
			elseif (($actor->category_filter_mode ?? 'off') === 'exclude')
			{
				$excluded = array_map('intval', array_filter(explode(',', $actor->category_filter_srls ?? ''), 'strlen'));
				if (in_array($category_srl, $excluded))
				{
					continue;
				}
			}

			// 활동 기록이 없으면 추가 (기존 게시물 호환)
			ActorModel::addActivity($actor->actor_srl, 'document', $obj->document_srl, $module_srl, $member_srl);

			if (self::isQueueAvailable())
			{
				$args = new \stdClass;
				$args->actor_srl = $actor->actor_srl;
				$args->document_srl = $obj->document_srl;
				$args->module_srl = $module_srl;
				$args->title = $obj->title ?? '';
				$args->content = $obj->content ?? '';
				$args->nick_name = $obj->nick_name ?? '';
				$args->activity_type = 'Update';

				\Rhymix\Framework\Queue::addTask(
					'Rhymix\\Modules\\Activitypub\\Controllers\\EventHandlers::processDocumentDeliveryTask',
					$args
				);
			}
			else
			{
				self::deliverDocumentUpdateToFollowers($actor, $obj);
			}
		}
	}

	/**
	 * document.deleteDocument before 트리거
	 * 게시물이 삭제될 때 ActivityPub으로 Delete 알림
	 *
	 * @param object $obj
	 */
	public function beforeDeleteDocument($obj)
	{
		// 모듈이 비활성화된 경우 즉시 반환
		$config = ConfigModel::getConfig();
		if (($config->module_enabled ?? 'Y') !== 'Y')
		{
			return;
		}

		self::debugLog('[beforeDeleteDocument] Triggered with document_srl=' . (isset($obj->document_srl) ? $obj->document_srl : 'null'));
		$this->sendDeleteDocumentActivity($obj);
	}

	/**
	 * document.moveDocumentToTrash before 트리거
	 * 게시물이 휴지통으로 이동될 때 ActivityPub으로 Delete 알림
	 *
	 * @param object $obj
	 */
	public function beforeMoveDocumentToTrash($obj)
	{
		// 모듈이 비활성화된 경우 즉시 반환
		$config = ConfigModel::getConfig();
		if (($config->module_enabled ?? 'Y') !== 'Y')
		{
			return;
		}

		self::debugLog('[beforeMoveDocumentToTrash] Triggered with document_srl=' . (isset($obj->document_srl) ? $obj->document_srl : 'null'));
		$this->sendDeleteDocumentActivity($obj);
	}

	/**
	 * 게시물 Delete Activity 전송 공통 처리
	 * activities 테이블에서 기록을 조회하여 Delete 전송
	 *
	 * @param object $obj
	 */
	protected function sendDeleteDocumentActivity($obj)
	{
		if (!$obj || !isset($obj->document_srl))
		{
			return;
		}

		$document_srl = $obj->document_srl;

		// activities 테이블에서 해당 게시물에 대한 활동 기록 조회
		$activities = ActorModel::getActivitiesByObjectSrl('document', $document_srl);
		if (empty($activities))
		{
			// 활동 기록이 없으면 AP로 발송된 적 없으므로 삭제할 필요 없음
			self::debugLog('[sendDeleteDocumentActivity] No activity records for document_srl=' . $document_srl . ', skipping');
			return;
		}

		self::debugLog('[sendDeleteDocumentActivity] Found ' . count($activities) . ' activity record(s) for document_srl=' . $document_srl);

		foreach ($activities as $activity)
		{
			$actor = ActorModel::getActor($activity->actor_srl);
			if (!$actor)
			{
				continue;
			}

			if (self::isQueueAvailable())
			{
				$args = new \stdClass;
				$args->actor_srl = $actor->actor_srl;
				$args->document_srl = $document_srl;
				$args->module_srl = $activity->module_srl;
				$args->activity_type = 'Delete';

				\Rhymix\Framework\Queue::addTask(
					'Rhymix\\Modules\\Activitypub\\Controllers\\EventHandlers::processDocumentDeliveryTask',
					$args
				);
			}
			else
			{
				self::deliverDocumentDeleteToFollowers($actor, $document_srl);
			}
		}

		// 활동 기록 삭제
		ActorModel::deleteActivitiesByObjectSrl('document', $document_srl);
	}

	/**
	 * comment.updateComment after 트리거
	 * 댓글이 수정될 때 ActivityPub으로 Update 알림
	 *
	 * @param object $obj
	 */
	public function afterUpdateComment($obj)
	{
		// 모듈이 비활성화된 경우 즉시 반환
		$config = ConfigModel::getConfig();
		if (($config->module_enabled ?? 'Y') !== 'Y')
		{
			return;
		}

		if (!$obj || !isset($obj->comment_srl))
		{
			return;
		}

		// 댓글 AP 전송이 비활성화된 경우 제외
		if (($config->send_comments ?? 'N') !== 'Y')
		{
			return;
		}

		// 비밀 댓글로 변경된 경우 Delete 전송
		if (isset($obj->is_secret) && $obj->is_secret === 'Y')
		{
			$this->sendDeleteCommentActivity($obj);
			return;
		}

		$module_srl = $obj->module_srl ?? 0;
		$document_srl = $obj->document_srl ?? 0;

		// 트리거에 module_srl/document_srl이 없을 수 있으므로 DB에서 조회
		if (!$module_srl || !$document_srl)
		{
			$comment = \CommentModel::getComment($obj->comment_srl);
			if ($comment && $comment->comment_srl)
			{
				$module_srl = $module_srl ?: intval($comment->module_srl);
				$document_srl = $document_srl ?: intval($comment->document_srl);
			}
		}

		if (!$module_srl)
		{
			return;
		}

		// 비로그인 상태로 접속 불가능한 게시판의 댓글은 AP로 발행하지 않음
		if (!ActorModel::isModulePubliclyAccessible($module_srl))
		{
			return;
		}

		$member_srl = $obj->member_srl ?? 0;
		$actors = ActorModel::getActorsForDocument($module_srl, $member_srl);
		if (empty($actors))
		{
			return;
		}

		foreach ($actors as $actor)
		{
			// 활동 기록이 없으면 추가 (기존 댓글 호환)
			ActorModel::addActivity($actor->actor_srl, 'comment', $obj->comment_srl, $module_srl, $member_srl);

			if (self::isQueueAvailable())
			{
				$args = new \stdClass;
				$args->actor_srl = $actor->actor_srl;
				$args->comment_srl = $obj->comment_srl;
				$args->document_srl = $document_srl;
				$args->module_srl = $module_srl;
				$args->content = $obj->content ?? '';
				$args->nick_name = $obj->nick_name ?? '';
				$args->activity_type = 'Update';

				\Rhymix\Framework\Queue::addTask(
					'Rhymix\\Modules\\Activitypub\\Controllers\\EventHandlers::processCommentDeliveryTask',
					$args
				);
			}
			else
			{
				self::deliverCommentUpdateToFollowers($actor, $obj);
			}
		}
	}

	/**
	 * comment.deleteComment before 트리거
	 * 댓글이 삭제될 때 ActivityPub으로 Delete 알림
	 *
	 * @param object $obj
	 */
	public function beforeDeleteComment($obj)
	{
		// 모듈이 비활성화된 경우 즉시 반환
		$config = ConfigModel::getConfig();
		if (($config->module_enabled ?? 'Y') !== 'Y')
		{
			return;
		}

		$this->sendDeleteCommentActivity($obj);
	}

	/**
	 * comment.moveCommentToTrash before 트리거
	 * 댓글이 휴지통으로 이동될 때 ActivityPub으로 Delete 알림
	 *
	 * @param object $obj
	 */
	public function beforeMoveCommentToTrash($obj)
	{
		// 모듈이 비활성화된 경우 즉시 반환
		$config = ConfigModel::getConfig();
		if (($config->module_enabled ?? 'Y') !== 'Y')
		{
			return;
		}

		$this->sendDeleteCommentActivity($obj);
	}

	/**
	 * 댓글 Delete Activity 전송 공통 처리
	 * activities 테이블에서 기록을 조회하여 Delete 전송
	 *
	 * @param object $obj
	 */
	protected function sendDeleteCommentActivity($obj)
	{
		if (!$obj || !isset($obj->comment_srl))
		{
			return;
		}

		// 댓글 AP 전송이 비활성화된 경우 제외
		$config = ConfigModel::getConfig();
		if (($config->send_comments ?? 'N') !== 'Y')
		{
			return;
		}

		$comment_srl = $obj->comment_srl;

		// activities 테이블에서 해당 댓글에 대한 활동 기록 조회
		$activities = ActorModel::getActivitiesByObjectSrl('comment', $comment_srl);
		if (empty($activities))
		{
			return;
		}

		foreach ($activities as $activity)
		{
			$actor = ActorModel::getActor($activity->actor_srl);
			if (!$actor)
			{
				continue;
			}

			if (self::isQueueAvailable())
			{
				$args = new \stdClass;
				$args->actor_srl = $actor->actor_srl;
				$args->comment_srl = $comment_srl;
				$args->module_srl = $activity->module_srl;
				$args->activity_type = 'Delete';

				\Rhymix\Framework\Queue::addTask(
					'Rhymix\\Modules\\Activitypub\\Controllers\\EventHandlers::processCommentDeliveryTask',
					$args
				);
			}
			else
			{
				self::deliverCommentDeleteToFollowers($actor, $comment_srl);
			}
		}

		// 활동 기록 삭제
		ActorModel::deleteActivitiesByObjectSrl('comment', $comment_srl);
	}

	/**
	 * moduleHandler.proc before 트리거
	 * WebFinger 요청을 가로채서 처리
	 *
	 * @param object $obj
	 */
	public function beforeModuleHandlerProc($obj)
	{
		// 모듈이 비활성화된 경우 즉시 반환
		$config = ConfigModel::getConfig();
		if (($config->module_enabled ?? 'Y') !== 'Y')
		{
			return;
		}

		$request_uri = $_SERVER['REQUEST_URI'] ?? '';

		// /.well-known/webfinger 요청 처리
		if (strpos($request_uri, '/.well-known/webfinger') === 0)
		{
			$this->handleWebfinger();
		}
	}

	/**
	 * WebFinger 요청 처리
	 */
	protected function handleWebfinger()
	{
		$resource = $_GET['resource'] ?? '';
		if (!$resource)
		{
			http_response_code(400);
			header('Content-Type: application/json; charset=utf-8');
			echo json_encode(['error' => 'Missing resource parameter']);
			exit;
		}

		// acct:username@domain 형식 파싱
		if (strpos($resource, 'acct:') !== 0)
		{
			http_response_code(400);
			header('Content-Type: application/json; charset=utf-8');
			echo json_encode(['error' => 'Invalid resource format']);
			exit;
		}

		$acct = substr($resource, 5);
		$parts = explode('@', $acct);
		if (count($parts) !== 2)
		{
			http_response_code(400);
			header('Content-Type: application/json; charset=utf-8');
			echo json_encode(['error' => 'Invalid account format']);
			exit;
		}

		$username = $parts[0];
		$domain = $parts[1];
		$site_domain = ActorModel::getSiteDomain();

		// 도메인 확인
		if ($domain !== $site_domain)
		{
			http_response_code(404);
			header('Content-Type: application/json; charset=utf-8');
			echo json_encode(['error' => 'Unknown domain']);
			exit;
		}

		// Actor 찾기
		$actor = ActorModel::getActiveActorByPreferredUsername($username);
		if (!$actor)
		{
			http_response_code(404);
			header('Content-Type: application/json; charset=utf-8');
			echo json_encode(['error' => 'Unknown user']);
			exit;
		}

		$actor_url = ActorModel::getActorUrl($actor->preferred_username);

		$response = [
			'subject' => $resource,
			'links' => [
				[
					'rel' => 'self',
					'type' => 'application/activity+json',
					'href' => $actor_url,
				],
			],
		];

		header('Content-Type: application/jrd+json; charset=utf-8');
		header('Access-Control-Allow-Origin: *');
		echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		exit;
	}

	/**
	 * 비동기 큐 사용 가능 여부 확인
	 *
	 * @return bool
	 */
	protected static function isQueueAvailable()
	{
		return config('queue.enabled');
	}

	/**
	 * Actor의 visibility 설정에 따른 to/cc 필드 결정
	 *
	 * @param object $actor
	 * @return array ['to' => [...], 'cc' => [...]]
	 */
	protected static function getVisibilityRecipients($actor)
	{
		$followers_url = ActorModel::getFollowersUrl($actor->preferred_username);
		$visibility = $actor->visibility ?? 'unlisted';

		switch ($visibility)
		{
			case 'public':
				return [
					'to' => ['https://www.w3.org/ns/activitystreams#Public'],
					'cc' => [$followers_url],
				];
			case 'private':
				return [
					'to' => [$followers_url],
					'cc' => [],
				];
			case 'direct':
				return [
					'to' => [],
					'cc' => [],
				];
			case 'unlisted':
			default:
				return [
					'to' => [$followers_url],
					'cc' => ['https://www.w3.org/ns/activitystreams#Public'],
				];
		}
	}

	/**
	 * 게시물에 대한 이미지 첨부 및 민감 표시 데이터 생성
	 * 첨부파일 목록에서 커버 이미지(썸네일로 지정된 원본 이미지)를 찾아 첨부
	 *
	 * @param object $actor
	 * @param int $document_srl
	 * @return array ['attachment' => [...], 'sensitive' => bool]
	 */
	protected static function getThumbnailData($actor, $document_srl)
	{
		$result = ['attachment' => null, 'sensitive' => false];

		if (($actor->attach_thumbnail ?? 'N') !== 'Y')
		{
			return $result;
		}

		$oDocument = \DocumentModel::getDocument($document_srl);
		if (!$oDocument || !$oDocument->document_srl)
		{
			return $result;
		}

		// 첨부파일 목록에서 커버 이미지(썸네일로 지정된 원본 이미지)를 찾기
		$files = $oDocument->getUploadedFiles();
		$coverFile = null;
		if (!empty($files))
		{
			foreach ($files as $file)
			{
				if (!empty($file->cover_image))
				{
					$coverFile = $file;
					break;
				}
			}
		}

		if (!$coverFile || empty($coverFile->uploaded_filename))
		{
			return $result;
		}

		// MIME 타입 검증
		$mediaType = $coverFile->mime_type ?: 'image/jpeg';
		if (!self::isAllowedImageMimeType($mediaType))
		{
			return $result;
		}

		// 원본 이미지 URL 생성
		$relativePath = ltrim($coverFile->uploaded_filename, './');
		$site_url = ActorModel::getSiteUrl();
		$imageUrl = rtrim($site_url, '/') . '/' . $relativePath;

		$result['attachment'] = [[
			'type' => 'Image',
			'mediaType' => $mediaType,
			'url' => $imageUrl,
		]];

		// 민감 이미지 확인
		$sensitive_mode = $actor->sensitive_mode ?? 'off';
		if ($sensitive_mode === 'always')
		{
			$result['sensitive'] = true;
		}
		elseif ($sensitive_mode === 'category')
		{
			$cat_srl = intval($oDocument->get('category_srl'));
			$sens_cats = array_map('intval', array_filter(explode(',', $actor->sensitive_category_srls ?? ''), 'strlen'));
			if (in_array($cat_srl, $sens_cats))
			{
				$result['sensitive'] = true;
			}
		}

		return $result;
	}

	/**
	 * 게시물 배달 큐 태스크 핸들러
	 *
	 * @param object $args
	 * @param object|null $options
	 */
	public static function processDocumentDeliveryTask($args, $options = null)
	{
		self::debugLog('[processDocumentDeliveryTask] Called with actor_srl=' . ($args->actor_srl ?? 'null') . ', document_srl=' . ($args->document_srl ?? 'null') . ', module_srl=' . ($args->module_srl ?? 'null') . ', activity_type=' . ($args->activity_type ?? 'Create'));

		if (!$args || !isset($args->actor_srl) || !isset($args->document_srl))
		{
			self::debugLog('[processDocumentDeliveryTask] Invalid args, returning');
			return;
		}

		$actor = ActorModel::getActor($args->actor_srl);
		if (!$actor)
		{
			self::debugLog('[processDocumentDeliveryTask] Actor not found for actor_srl=' . $args->actor_srl);
			return;
		}

		$activity_type = $args->activity_type ?? 'Create';
		self::debugLog('[processDocumentDeliveryTask] Delivering document_srl=' . $args->document_srl . ' via actor=' . $actor->preferred_username . ' type=' . $activity_type);

		switch ($activity_type)
		{
			case 'Update':
				self::deliverDocumentUpdateToFollowers($actor, $args);
				break;
			case 'Delete':
				self::deliverDocumentDeleteToFollowers($actor, $args->document_srl);
				break;
			default:
				self::deliverDocumentToFollowers($actor, $args);
				break;
		}
	}

	/**
	 * 댓글 배달 큐 태스크 핸들러
	 *
	 * @param object $args
	 * @param object|null $options
	 */
	public static function processCommentDeliveryTask($args, $options = null)
	{
		self::debugLog('[processCommentDeliveryTask] Called with actor_srl=' . ($args->actor_srl ?? 'null') . ', comment_srl=' . ($args->comment_srl ?? 'null') . ', document_srl=' . ($args->document_srl ?? 'null') . ', module_srl=' . ($args->module_srl ?? 'null') . ', activity_type=' . ($args->activity_type ?? 'Create'));

		if (!$args || !isset($args->actor_srl) || !isset($args->comment_srl))
		{
			self::debugLog('[processCommentDeliveryTask] Invalid args, returning');
			return;
		}

		$actor = ActorModel::getActor($args->actor_srl);
		if (!$actor)
		{
			self::debugLog('[processCommentDeliveryTask] Actor not found for actor_srl=' . $args->actor_srl);
			return;
		}

		$activity_type = $args->activity_type ?? 'Create';
		self::debugLog('[processCommentDeliveryTask] Delivering comment_srl=' . $args->comment_srl . ' via actor=' . $actor->preferred_username . ' type=' . $activity_type);

		switch ($activity_type)
		{
			case 'Update':
				self::deliverCommentUpdateToFollowers($actor, $args);
				break;
			case 'Delete':
				self::deliverCommentDeleteToFollowers($actor, $args->comment_srl);
				break;
			default:
				self::deliverCommentToFollowers($actor, $args);
				break;
		}
	}

	/**
	 * 게시물을 팔로워에게 배달
	 *
	 * @param object $actor
	 * @param object $document
	 */
	protected static function deliverDocumentToFollowers($actor, $document)
	{
		self::debugLog('[deliverDocumentToFollowers] Start: actor=' . $actor->preferred_username . ', document_srl=' . $document->document_srl);

		TypeConfiguration::set('undefined_properties', 'include');

		$site_url = ActorModel::getSiteUrl();
		$actor_url = ActorModel::getActorUrl($actor->preferred_username);
		$document_srl = $document->document_srl;

		// 게시물 URL 생성 (문서가 속한 게시판 mid 사용)
		$mid = \ModuleModel::getMidByModuleSrl($document->module_srl);
		$document_url = $site_url . '?mid=' . urlencode($mid) . '&document_srl=' . $document_srl;

		// 제목과 내용
		$title = $document->title ?? '';
		$content = $document->content ?? '';
		$nick_name = $document->nick_name ?? '';

		$content_text = self::truncateContent($content);

		// HTML 컨텐츠 생성
		$noteContent = self::buildDocumentNoteContent($title, $content_text, $nick_name, $document_url, $actor);
		$html_content = $noteContent['content'];

		$published = date('c');
		$note_id = ActorModel::getNoteUrl($actor->preferred_username, $document_srl);
		$recipients = self::getVisibilityRecipients($actor);

		$note_data = [
			'id' => $note_id,
			'published' => $published,
			'attributedTo' => $actor_url,
			'content' => $html_content,
			'url' => $document_url,
			'to' => $recipients['to'],
			'cc' => $recipients['cc'],
		];
		if ($noteContent['summary'] !== null)
		{
			$note_data['summary'] = $noteContent['summary'];
		}

		// 이미지 첨부 및 민감 표시
		$thumbData = self::getThumbnailData($actor, $document_srl);
		if ($thumbData['attachment'])
		{
			$note_data['attachment'] = $thumbData['attachment'];
		}
		if ($thumbData['sensitive'])
		{
			$note_data['sensitive'] = true;
		}

		$note = Type::create('Note', $note_data);

		$activity = Type::create('Create', [
			'@context' => 'https://www.w3.org/ns/activitystreams',
			'id' => $note_id . '&type=activity',
			'actor' => $actor_url,
			'published' => $published,
			'to' => $recipients['to'],
			'cc' => $recipients['cc'],
			'object' => $note->toArray(),
		]);

		self::debugLog('[deliverDocumentToFollowers] Activity created: note_id=' . $note_id . ', calling deliverToFollowers');

		// 팔로워에게 전송
		self::deliverToFollowers($actor, $activity);
	}

	/**
	 * 댓글을 팔로워에게 배달
	 *
	 * @param object $actor
	 * @param object $comment
	 */
	protected static function deliverCommentToFollowers($actor, $comment)
	{
		self::debugLog('[deliverCommentToFollowers] Start: actor=' . $actor->preferred_username . ', comment_srl=' . $comment->comment_srl . ', document_srl=' . $comment->document_srl);

		TypeConfiguration::set('undefined_properties', 'include');

		$site_url = ActorModel::getSiteUrl();
		$actor_url = ActorModel::getActorUrl($actor->preferred_username);
		$comment_srl = $comment->comment_srl;
		$document_srl = $comment->document_srl;

		// 부모 게시물 URL 생성 (문서가 속한 게시판 mid 사용)
		$mid = \ModuleModel::getMidByModuleSrl($comment->module_srl);
		$document_url = $site_url . '?mid=' . urlencode($mid) . '&document_srl=' . $document_srl;
		$comment_url = $document_url . '#comment_' . $comment_srl;

		// 내용
		$content = $comment->content ?? '';
		$content_text = self::truncateContent($content);
		$nick_name = $comment->nick_name ?? '';

		// HTML 컨텐츠 생성
		$noteContent = self::buildCommentNoteContent($content_text, $nick_name, $comment_url, $actor);
		$html_content = $noteContent['content'];

		$published = date('c');
		$note_id = ActorModel::getCommentNoteUrl($actor->preferred_username, $comment_srl);
		$parent_note_id = ActorModel::getNoteUrl($actor->preferred_username, $document_srl);
		$recipients = self::getVisibilityRecipients($actor);

		$note_data = [
			'id' => $note_id,
			'published' => $published,
			'attributedTo' => $actor_url,
			'content' => $html_content,
			'url' => $comment_url,
			'inReplyTo' => $parent_note_id,
			'to' => $recipients['to'],
			'cc' => $recipients['cc'],
		];
		if ($noteContent['summary'] !== null)
		{
			$note_data['summary'] = $noteContent['summary'];
		}

		$note = Type::create('Note', $note_data);

		$activity = Type::create('Create', [
			'@context' => 'https://www.w3.org/ns/activitystreams',
			'id' => $note_id . '&type=activity',
			'actor' => $actor_url,
			'published' => $published,
			'to' => $recipients['to'],
			'cc' => $recipients['cc'],
			'object' => $note->toArray(),
		]);

		self::debugLog('[deliverCommentToFollowers] Activity created: note_id=' . $note_id . ', calling deliverToFollowers');

		self::deliverToFollowers($actor, $activity);
	}

	/**
	 * 게시물 Update Activity를 팔로워에게 배달
	 *
	 * @param object $actor
	 * @param object $document
	 */
	protected static function deliverDocumentUpdateToFollowers($actor, $document)
	{
		self::debugLog('[deliverDocumentUpdateToFollowers] Start: actor=' . $actor->preferred_username . ', document_srl=' . $document->document_srl);

		TypeConfiguration::set('undefined_properties', 'include');

		$site_url = ActorModel::getSiteUrl();
		$actor_url = ActorModel::getActorUrl($actor->preferred_username);
		$document_srl = $document->document_srl;

		$mid = \ModuleModel::getMidByModuleSrl($document->module_srl);
		$document_url = $site_url . '?mid=' . urlencode($mid) . '&document_srl=' . $document_srl;

		$title = $document->title ?? '';
		$content = $document->content ?? '';
		$nick_name = $document->nick_name ?? '';

		$content_text = self::truncateContent($content);

		$noteContent = self::buildDocumentNoteContent($title, $content_text, $nick_name, $document_url, $actor);
		$html_content = $noteContent['content'];

		$updated = date('c');
		$note_id = ActorModel::getNoteUrl($actor->preferred_username, $document_srl);
		$recipients = self::getVisibilityRecipients($actor);

		$note_data = [
			'id' => $note_id,
			'updated' => $updated,
			'attributedTo' => $actor_url,
			'content' => $html_content,
			'url' => $document_url,
			'to' => $recipients['to'],
			'cc' => $recipients['cc'],
		];
		if ($noteContent['summary'] !== null)
		{
			$note_data['summary'] = $noteContent['summary'];
		}

		// 이미지 첨부 및 민감 표시
		$thumbData = self::getThumbnailData($actor, $document_srl);
		if ($thumbData['attachment'])
		{
			$note_data['attachment'] = $thumbData['attachment'];
		}
		if ($thumbData['sensitive'])
		{
			$note_data['sensitive'] = true;
		}

		$note = Type::create('Note', $note_data);

		$activity = Type::create('Update', [
			'@context' => 'https://www.w3.org/ns/activitystreams',
			'id' => $note_id . '&type=activity&updated=' . urlencode($updated),
			'actor' => $actor_url,
			'published' => $updated,
			'to' => $recipients['to'],
			'cc' => $recipients['cc'],
			'object' => $note->toArray(),
		]);

		self::debugLog('[deliverDocumentUpdateToFollowers] Activity created: note_id=' . $note_id . ', calling deliverToFollowers');

		self::deliverToFollowers($actor, $activity);
	}

	/**
	 * 게시물 Delete Activity를 팔로워에게 배달
	 *
	 * @param object $actor
	 * @param int $document_srl
	 */
	protected static function deliverDocumentDeleteToFollowers($actor, $document_srl)
	{
		self::debugLog('[deliverDocumentDeleteToFollowers] Start: actor=' . $actor->preferred_username . ', document_srl=' . $document_srl);

		TypeConfiguration::set('undefined_properties', 'include');

		$actor_url = ActorModel::getActorUrl($actor->preferred_username);
		$note_id = ActorModel::getNoteUrl($actor->preferred_username, $document_srl);
		$recipients = self::getVisibilityRecipients($actor);

		// Mastodon 스타일 Delete: object에 Tombstone 사용
		$tombstone = Type::create('Tombstone', [
			'id' => $note_id,
		]);

		$activity = Type::create('Delete', [
			'@context' => 'https://www.w3.org/ns/activitystreams',
			'id' => $note_id . '&type=activity#delete',
			'actor' => $actor_url,
			'to' => $recipients['to'],
			'cc' => $recipients['cc'],
			'object' => $tombstone->toArray(),
		]);

		self::debugLog('[deliverDocumentDeleteToFollowers] Activity created: note_id=' . $note_id . ', calling deliverToFollowers');

		self::deliverToFollowers($actor, $activity);
	}

	/**
	 * 댓글 Update Activity를 팔로워에게 배달
	 *
	 * @param object $actor
	 * @param object $comment
	 */
	protected static function deliverCommentUpdateToFollowers($actor, $comment)
	{
		self::debugLog('[deliverCommentUpdateToFollowers] Start: actor=' . $actor->preferred_username . ', comment_srl=' . $comment->comment_srl);

		TypeConfiguration::set('undefined_properties', 'include');

		$site_url = ActorModel::getSiteUrl();
		$actor_url = ActorModel::getActorUrl($actor->preferred_username);
		$comment_srl = $comment->comment_srl;
		$document_srl = $comment->document_srl;

		$mid = \ModuleModel::getMidByModuleSrl($comment->module_srl);
		$document_url = $site_url . '?mid=' . urlencode($mid) . '&document_srl=' . $document_srl;
		$comment_url = $document_url . '#comment_' . $comment_srl;

		$content = $comment->content ?? '';
		$content_text = self::truncateContent($content);
		$nick_name = $comment->nick_name ?? '';

		// HTML 컨텐츠 생성
		$noteContent = self::buildCommentNoteContent($content_text, $nick_name, $comment_url, $actor);
		$html_content = $noteContent['content'];

		$updated = date('c');
		$note_id = ActorModel::getCommentNoteUrl($actor->preferred_username, $comment_srl);
		$parent_note_id = ActorModel::getNoteUrl($actor->preferred_username, $document_srl);
		$recipients = self::getVisibilityRecipients($actor);

		$note_data = [
			'id' => $note_id,
			'updated' => $updated,
			'attributedTo' => $actor_url,
			'content' => $html_content,
			'url' => $comment_url,
			'inReplyTo' => $parent_note_id,
			'to' => $recipients['to'],
			'cc' => $recipients['cc'],
		];
		if ($noteContent['summary'] !== null)
		{
			$note_data['summary'] = $noteContent['summary'];
		}

		$note = Type::create('Note', $note_data);

		$activity = Type::create('Update', [
			'@context' => 'https://www.w3.org/ns/activitystreams',
			'id' => $note_id . '&type=activity&updated=' . urlencode($updated),
			'actor' => $actor_url,
			'published' => $updated,
			'to' => $recipients['to'],
			'cc' => $recipients['cc'],
			'object' => $note->toArray(),
		]);

		self::debugLog('[deliverCommentUpdateToFollowers] Activity created: note_id=' . $note_id . ', calling deliverToFollowers');

		self::deliverToFollowers($actor, $activity);
	}

	/**
	 * 댓글 Delete Activity를 팔로워에게 배달
	 *
	 * @param object $actor
	 * @param int $comment_srl
	 */
	protected static function deliverCommentDeleteToFollowers($actor, $comment_srl)
	{
		self::debugLog('[deliverCommentDeleteToFollowers] Start: actor=' . $actor->preferred_username . ', comment_srl=' . $comment_srl);

		TypeConfiguration::set('undefined_properties', 'include');

		$actor_url = ActorModel::getActorUrl($actor->preferred_username);
		$note_id = ActorModel::getCommentNoteUrl($actor->preferred_username, $comment_srl);
		$recipients = self::getVisibilityRecipients($actor);

		$tombstone = Type::create('Tombstone', [
			'id' => $note_id,
		]);

		$activity = Type::create('Delete', [
			'@context' => 'https://www.w3.org/ns/activitystreams',
			'id' => $note_id . '&type=activity#delete',
			'actor' => $actor_url,
			'to' => $recipients['to'],
			'cc' => $recipients['cc'],
			'object' => $tombstone->toArray(),
		]);

		self::debugLog('[deliverCommentDeleteToFollowers] Activity created: note_id=' . $note_id . ', calling deliverToFollowers');

		self::deliverToFollowers($actor, $activity);
	}

	/**
	 * Activity를 팔로워에게 배달
	 *
	 * @param object $actor
	 * @param \ActivityPhp\Type\AbstractObject $activity
	 */
	protected static function deliverToFollowers($actor, $activity)
	{
		$followers_output = ActorModel::getFollowers($actor->actor_srl);
		if (!$followers_output->toBool() || empty($followers_output->data))
		{
			self::debugLog('[deliverToFollowers] No followers found for actor_srl=' . $actor->actor_srl);
			return;
		}

		$followers = is_array($followers_output->data) ? $followers_output->data : [$followers_output->data];
		$body = $activity->toJson(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

		self::debugLog('[deliverToFollowers] Delivering to ' . count($followers) . ' follower(s) for actor_srl=' . $actor->actor_srl);

		// Shared inbox 를 사용하여 중복 전송 방지
		$delivered_inboxes = [];
		foreach ($followers as $follower)
		{
			$inbox_url = $follower->follower_shared_inbox_url ?: $follower->follower_inbox_url;
			if (in_array($inbox_url, $delivered_inboxes))
			{
				self::debugLog('[deliverToFollowers] Skipping duplicate inbox: ' . $inbox_url);
				continue;
			}
			$delivered_inboxes[] = $inbox_url;

			self::debugLog('[deliverToFollowers] Sending to inbox: ' . $inbox_url);
			self::sendSignedRequest($actor, $inbox_url, $body);
		}
	}

	/**
	 * HTTP Signature로 서명된 요청 전송
	 *
	 * @param object $actor
	 * @param string $url
	 * @param string $body
	 * @return bool
	 */
	public static function sendSignedRequest($actor, $url, $body)
	{
		$parsed = parse_url($url);
		if (!$parsed || !isset($parsed['host']))
		{
			self::debugLog('[sendSignedRequest] Invalid URL: ' . $url);
			return false;
		}

		$host = $parsed['host'];
		$path = $parsed['path'] ?? '/';
		if (!empty($parsed['query']))
		{
			$path .= '?' . $parsed['query'];
		}

		$date = gmdate('D, d M Y H:i:s \G\M\T');
		$digest = 'SHA-256=' . base64_encode(hash('sha256', $body, true));

		$actor_url = ActorModel::getActorUrl($actor->preferred_username);
		$key_id = $actor_url . '#main-key';

		// 서명 문자열 생성
		$signing_string = "(request-target): post " . $path . "\n";
		$signing_string .= "host: " . $host . "\n";
		$signing_string .= "date: " . $date . "\n";
		$signing_string .= "digest: " . $digest;

		// RSA-SHA256 서명
		$private_key = openssl_pkey_get_private($actor->private_key);
		if (!$private_key)
		{
			self::debugLog('[sendSignedRequest] Failed to load private key for actor=' . $actor->preferred_username);
			return false;
		}

		$signature = '';
		$success = openssl_sign($signing_string, $signature, $private_key, OPENSSL_ALGO_SHA256);
		if (!$success)
		{
			self::debugLog('[sendSignedRequest] Failed to sign request for URL: ' . $url);
			return false;
		}

		$signature_b64 = base64_encode($signature);
		$signature_header = 'keyId="' . $key_id . '",algorithm="rsa-sha256",headers="(request-target) host date digest",signature="' . $signature_b64 . '"';

		// HTTP 요청 전송
		$headers = [
			'Content-Type: application/activity+json',
			'Host: ' . $host,
			'Date: ' . $date,
			'Digest: ' . $digest,
			'Signature: ' . $signature_header,
			'Accept: application/activity+json',
		];

		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $body,
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_TIMEOUT => 10,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS => 3,
			CURLOPT_USERAGENT => 'RhymixActivityPub/1.0',
		]);

		$response = curl_exec($ch);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curl_error = curl_error($ch);
		curl_close($ch);

		$result = ($http_code >= 200 && $http_code < 300);
		if ($result)
		{
			self::debugLog('[sendSignedRequest] Success: URL=' . $url . ', HTTP ' . $http_code);
		}
		else
		{
			self::debugLog('[sendSignedRequest] Failed: URL=' . $url . ', HTTP ' . $http_code . ', curl_error=' . $curl_error . ', response=' . mb_substr($response ?: '', 0, 200));
		}

		return $result;
	}
}
