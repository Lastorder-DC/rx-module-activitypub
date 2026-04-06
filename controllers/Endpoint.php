<?php

namespace Rhymix\Modules\Activitypub\Controllers;

use Rhymix\Modules\Activitypub\Models\Actor as ActorModel;
use Rhymix\Modules\Activitypub\Models\Config as ConfigModel;
use ActivityPhp\Type;
use ActivityPhp\Type\TypeConfiguration;
use Context;
use ModuleModel;

/**
 * ActivityPub 연동 모듈 - ActivityPub 엔드포인트 컨트롤러
 *
 * Copyright (c) Lastorder-DC
 * Licensed under GPLv2
 */
class Endpoint extends Base
{
	/**
	 * HTTP Signature 헤더 파싱 패턴 (draft-cavage-http-signatures-12 형식)
	 */
	public const SIGNATURE_HEADER_PATTERN = '/keyId="(?P<keyId>[^"]+)",\s*(algorithm="(?P<algorithm>[^"]+)",\s*)?(headers="(?P<headers>[^"]+)",\s*)?signature="(?P<signature>[^"]+)"/';

	/**
	 * 초기화
	 */
	public function init()
	{
		TypeConfiguration::set('undefined_properties', 'include');
	}

	/**
	 * WebFinger 엔드포인트
	 */
	public function dispActivitypubWebfinger()
	{
		$resource = Context::get('resource');
		if (!$resource)
		{
			$this->sendJsonResponse(['error' => 'Missing resource parameter'], 400);
			return;
		}

		// acct:username@domain 형식 파싱
		if (strpos($resource, 'acct:') !== 0)
		{
			$this->sendJsonResponse(['error' => 'Invalid resource format'], 400);
			return;
		}

		$acct = substr($resource, 5);
		$parts = explode('@', $acct);
		if (count($parts) !== 2)
		{
			$this->sendJsonResponse(['error' => 'Invalid account format'], 400);
			return;
		}

		$username = $parts[0];
		$domain = $parts[1];
		$site_domain = ActorModel::getSiteDomain();

		if ($domain !== $site_domain)
		{
			$this->sendJsonResponse(['error' => 'Unknown domain'], 404);
			return;
		}

		$actor = ActorModel::getActiveActorByPreferredUsername($username);
		if (!$actor)
		{
			$this->sendJsonResponse(['error' => 'Unknown user'], 404);
			return;
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

		header('Access-Control-Allow-Origin: *');
		$this->sendJsonResponse($response, 200, 'application/jrd+json');
	}

	/**
	 * Actor 프로필 엔드포인트
	 */
	public function dispActivitypubActor()
	{
		self::debugLog('=== dispActivitypubActor START ===');
		self::debugLog('REQUEST_METHOD: ' . ($_SERVER['REQUEST_METHOD'] ?? '(none)'));
		self::debugLog('REQUEST_URI: ' . ($_SERVER['REQUEST_URI'] ?? '(none)'));
		self::debugLog('HTTP_ACCEPT: ' . ($_SERVER['HTTP_ACCEPT'] ?? '(none)'));
		self::debugLog('HTTP_SIGNATURE: ' . ($_SERVER['HTTP_SIGNATURE'] ?? '(none)'));
		self::debugLog('HTTP_SIGNATURE_INPUT: ' . ($_SERVER['HTTP_SIGNATURE_INPUT'] ?? '(none)'));
		self::debugLog('HTTP_HOST: ' . ($_SERVER['HTTP_HOST'] ?? '(none)'));
		self::debugLog('HTTP_DATE: ' . ($_SERVER['HTTP_DATE'] ?? '(none)'));
		self::debugLog('HTTP_USER_AGENT: ' . ($_SERVER['HTTP_USER_AGENT'] ?? '(none)'));

		// Authorized Fetch 모드일 경우 HTTP Signature 검증
		if (!$this->checkAuthorizedFetch())
		{
			self::debugLog('checkAuthorizedFetch() FAILED - returning 401');
			return;
		}
		self::debugLog('checkAuthorizedFetch() PASSED');

		$preferred_username = Context::get('preferred_username');
		if (!$preferred_username)
		{
			$this->sendJsonResponse(['error' => 'Missing username'], 400);
			return;
		}

		$actor = ActorModel::getActiveActorByPreferredUsername($preferred_username);
		if (!$actor)
		{
			$this->sendJsonResponse(['error' => 'Unknown user'], 404);
			return;
		}

		$actor_url = ActorModel::getActorUrl($actor->preferred_username);
		$inbox_url = ActorModel::getInboxUrl($actor->preferred_username);
		$outbox_url = ActorModel::getOutboxUrl($actor->preferred_username);
		$followers_url = ActorModel::getFollowersUrl($actor->preferred_username);
		$following_url = ActorModel::getFollowingUrl($actor->preferred_username);
		$shared_inbox_url = ActorModel::getSharedInboxUrl();
		$actor_type = $actor->actor_type ?? 'board';

		// Actor 타입에 따라 기본값 결정
		$name = '';
		$summary = '';
		$profile_url = '';
		$ap_type = 'Service';
		$attachment = [];

		if ($actor_type === 'board' && $actor->module_srl)
		{
			$mid = ModuleModel::getMidByModuleSrl($actor->module_srl);
			$module_info = ModuleModel::getModuleInfoByModuleSrl($actor->module_srl);
			$board_url = ActorModel::getSiteUrl() . '?mid=' . urlencode($mid);
			$profile_url = $board_url;

			// 표시 이름
			if (!empty($actor->display_name))
			{
				$name = $actor->display_name;
			}
			elseif ($module_info && !empty($module_info->browser_title))
			{
				$name = $module_info->browser_title;
			}
			elseif ($mid)
			{
				$name = $mid;
			}
			else
			{
				$name = $actor->preferred_username;
			}

			// 설명
			if (!empty($actor->summary))
			{
				$summary = $actor->summary;
			}
			elseif ($module_info && !empty($module_info->description))
			{
				$summary = $module_info->description;
			}

			// 메타데이터: 게시판 링크
			$attachment[] = [
				'type' => 'PropertyValue',
				'name' => 'Board',
				'value' => '<a href="' . htmlspecialchars($board_url, ENT_QUOTES, 'UTF-8') . '" rel="me nofollow noopener noreferrer" target="_blank">' . htmlspecialchars($board_url, ENT_QUOTES, 'UTF-8') . '</a>',
			];
		}
		elseif ($actor_type === 'user' && $actor->member_srl)
		{
			$ap_type = 'Person';
			$member_info = \MemberModel::getMemberInfoByMemberSrl($actor->member_srl);
			$profile_url = ActorModel::getSiteUrl() . '?act=dispMemberInfo&member_srl=' . $actor->member_srl;

			// 표시 이름
			if (!empty($actor->display_name))
			{
				$name = $actor->display_name;
			}
			elseif ($member_info && !empty($member_info->nick_name))
			{
				$name = $member_info->nick_name;
			}
			else
			{
				$name = $actor->preferred_username;
			}

			// 설명
			if (!empty($actor->summary))
			{
				$summary = $actor->summary;
			}

			// 프로필 이미지 기본값: 회원 프로필 사진
			if (empty($actor->icon_url) && $member_info && !empty($member_info->profile_image->src))
			{
				$actor->icon_url = $member_info->profile_image->src;
			}

			// 메타데이터: 프로필 링크
			$attachment[] = [
				'type' => 'PropertyValue',
				'name' => 'Profile',
				'value' => '<a href="' . htmlspecialchars($profile_url, ENT_QUOTES, 'UTF-8') . '" rel="me nofollow noopener noreferrer" target="_blank">' . htmlspecialchars($profile_url, ENT_QUOTES, 'UTF-8') . '</a>',
			];
		}
		else
		{
			$name = $actor->display_name ?: $actor->preferred_username;
			$summary = $actor->summary ?? '';
			$profile_url = $actor_url;
		}

		$actorType = Type::create($ap_type, [
			'@context' => [
				'https://www.w3.org/ns/activitystreams',
				'https://w3id.org/security/v1',
				[
					'schema' => 'http://schema.org#',
					'PropertyValue' => 'schema:PropertyValue',
					'value' => 'schema:value',
					'toot' => 'http://joinmastodon.org/ns#',
					'discoverable' => 'toot:discoverable',
				],
			],
			'id' => $actor_url,
			'preferredUsername' => $actor->preferred_username,
			'name' => $name,
			'summary' => $summary,
			'inbox' => $inbox_url,
			'outbox' => $outbox_url,
			'followers' => $followers_url,
			'following' => $following_url,
			'url' => $profile_url,
			'discoverable' => true,
			'published' => self::formatRegdateToIso($actor->regdate),
			'publicKey' => [
				'id' => $actor_url . '#main-key',
				'owner' => $actor_url,
				'publicKeyPem' => $actor->public_key,
			],
			'endpoints' => [
				'sharedInbox' => $shared_inbox_url,
			],
		]);

		if (!empty($attachment))
		{
			$actorType->set('attachment', $attachment);
		}

		// 프로필 이미지가 설정된 경우 icon 필드 추가
		if (!empty($actor->icon_url))
		{
			$actorType->set('icon', [
				'type' => 'Image',
				'url' => $actor->icon_url,
			]);
		}

		$this->sendActivityResponse($actorType);
	}

	/**
	 * Outbox 엔드포인트
	 * page 파라미터가 없으면 OrderedCollection (요약), 있으면 OrderedCollectionPage (실제 콘텐츠)
	 */
	public function dispActivitypubOutbox()
	{
		// Authorized Fetch 모드일 경우 HTTP Signature 검증
		if (!$this->checkAuthorizedFetch())
		{
			return;
		}

		$preferred_username = Context::get('preferred_username');
		if (!$preferred_username)
		{
			$this->sendJsonResponse(['error' => 'Missing username'], 400);
			return;
		}

		$actor = ActorModel::getActiveActorByPreferredUsername($preferred_username);
		if (!$actor)
		{
			$this->sendJsonResponse(['error' => 'Unknown user'], 404);
			return;
		}

		$actor_url = ActorModel::getActorUrl($actor->preferred_username);
		$outbox_url = ActorModel::getOutboxUrl($actor->preferred_username);
		$followers_url = ActorModel::getFollowersUrl($actor->preferred_username);
		$site_url = ActorModel::getSiteUrl();
		$page = intval(Context::get('page'));

		// 페이지 파라미터가 없으면 OrderedCollection 요약 반환
		if ($page < 1)
		{
			$documents_output = ActorModel::getDocumentsForActor($actor, 1, 1);
			$total_items = intval($documents_output->total_count ?? 0);

			$collection_data = [
				'@context' => 'https://www.w3.org/ns/activitystreams',
				'id' => $outbox_url,
				'totalItems' => $total_items,
			];

			if ($total_items > 0)
			{
				$collection_data['first'] = $outbox_url . '&page=1';
			}

			$outboxCollection = Type::create('OrderedCollection', $collection_data);
			$this->sendActivityResponse($outboxCollection);
			return;
		}

		// 페이지별 게시물 가져오기
		$list_count = 20;
		$documents_output = ActorModel::getDocumentsForActor($actor, $page, $list_count);
		$total_items = intval($documents_output->total_count ?? 0);
		$documents = $documents_output->data ?? [];
		if (!is_array($documents))
		{
			$documents = $documents ? [$documents] : [];
		}

		// 각 게시물을 Create(Note) Activity로 변환
		$ordered_items = [];
		foreach ($documents as $doc)
		{
			$document_srl = $doc->document_srl;
			$module_srl = $doc->module_srl;
			$mid = ModuleModel::getMidByModuleSrl($module_srl);
			$document_url = $site_url . '?mid=' . urlencode($mid) . '&document_srl=' . $document_srl;

			$title = $doc->title ?? '';
			$content = $doc->content ?? '';

			// HTML을 평문으로 변환
			$content_text = strip_tags($content);
			if (mb_strlen($content_text) > 500)
			{
				$content_text = mb_substr($content_text, 0, 497) . '...';
			}

			$html_content = '<p><strong>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</strong></p>';
			$html_content .= '<p>' . htmlspecialchars($content_text, ENT_QUOTES, 'UTF-8') . '</p>';
			$html_content .= '<p><a href="' . htmlspecialchars($document_url, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($document_url, ENT_QUOTES, 'UTF-8') . '</a></p>';

			$published = self::formatRegdateToIso($doc->regdate ?? '');
			$note_id = ActorModel::getNoteUrl($actor->preferred_username, $document_srl);

			$note = Type::create('Note', [
				'id' => $note_id,
				'published' => $published,
				'attributedTo' => $actor_url,
				'content' => $html_content,
				'url' => $document_url,
				'to' => ['https://www.w3.org/ns/activitystreams#Public'],
				'cc' => [$followers_url],
			]);

			// 수정일이 있고 등록일과 다르면 updated 필드 추가
			if (!empty($doc->last_update) && ($doc->last_update ?? '') !== ($doc->regdate ?? ''))
			{
				$note->set('updated', self::formatRegdateToIso($doc->last_update));
			}

			$activity = Type::create('Create', [
				'id' => $note_id . '&type=activity',
				'actor' => $actor_url,
				'published' => $published,
				'to' => ['https://www.w3.org/ns/activitystreams#Public'],
				'cc' => [$followers_url],
				'object' => $note->toArray(),
			]);

			$ordered_items[] = $activity->toArray();
		}

		// OrderedCollectionPage 생성
		$page_data = [
			'@context' => 'https://www.w3.org/ns/activitystreams',
			'id' => $outbox_url . '&page=' . $page,
			'partOf' => $outbox_url,
			'orderedItems' => $ordered_items,
		];

		// 다음 페이지 링크
		$total_pages = $total_items > 0 ? ceil($total_items / $list_count) : 1;
		if ($page < $total_pages)
		{
			$page_data['next'] = $outbox_url . '&page=' . ($page + 1);
		}
		if ($page > 1)
		{
			$page_data['prev'] = $outbox_url . '&page=' . ($page - 1);
		}

		$outboxPage = Type::create('OrderedCollectionPage', $page_data);
		$this->sendActivityResponse($outboxPage);
	}

	/**
	 * Note 엔드포인트
	 * 개별 게시물(document) 또는 댓글(comment)을 Note 객체로 반환
	 */
	public function dispActivitypubNote()
	{
		// Authorized Fetch 모드일 경우 HTTP Signature 검증
		if (!$this->checkAuthorizedFetch())
		{
			return;
		}

		$preferred_username = Context::get('preferred_username');
		if (!$preferred_username)
		{
			$this->sendJsonResponse(['error' => 'Missing username'], 400);
			return;
		}

		$actor = ActorModel::getActiveActorByPreferredUsername($preferred_username);
		if (!$actor)
		{
			$this->sendJsonResponse(['error' => 'Unknown user'], 404);
			return;
		}

		$actor_url = ActorModel::getActorUrl($actor->preferred_username);
		$followers_url = ActorModel::getFollowersUrl($actor->preferred_username);
		$site_url = ActorModel::getSiteUrl();

		$document_srl = intval(Context::get('document_srl'));
		$comment_srl = intval(Context::get('comment_srl'));

		// 댓글 Note
		if ($comment_srl > 0)
		{
			$comment = \CommentModel::getComment($comment_srl);
			if (!$comment || !$comment->comment_srl)
			{
				$this->sendJsonResponse(['error' => 'Not found'], 404);
				return;
			}

			// 비밀 댓글 제외
			if (($comment->is_secret ?? '') === 'Y')
			{
				$this->sendJsonResponse(['error' => 'Not found'], 404);
				return;
			}

			// 비공개 게시판 게시물 제외
			if (!ActorModel::isModulePubliclyAccessible($comment->module_srl))
			{
				$this->sendJsonResponse(['error' => 'Not found'], 404);
				return;
			}

			$document_srl = $comment->document_srl;
			$mid = ModuleModel::getMidByModuleSrl($comment->module_srl);
			$document_url = $site_url . '?mid=' . urlencode($mid) . '&document_srl=' . $document_srl;
			$comment_url = $document_url . '#comment_' . $comment_srl;

			$content = $comment->content ?? '';
			$content_text = strip_tags($content);
			if (mb_strlen($content_text) > 500)
			{
				$content_text = mb_substr($content_text, 0, 497) . '...';
			}

			$html_content = '<p>' . htmlspecialchars($content_text, ENT_QUOTES, 'UTF-8') . '</p>';
			$html_content .= '<p><a href="' . htmlspecialchars($comment_url, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($comment_url, ENT_QUOTES, 'UTF-8') . '</a></p>';

			$note_id = ActorModel::getCommentNoteUrl($actor->preferred_username, $comment_srl);
			$parent_note_id = ActorModel::getNoteUrl($actor->preferred_username, $document_srl);
			$published = self::formatRegdateToIso($comment->regdate ?? '');

			$note = Type::create('Note', [
				'@context' => 'https://www.w3.org/ns/activitystreams',
				'id' => $note_id,
				'published' => $published,
				'attributedTo' => $actor_url,
				'content' => $html_content,
				'url' => $comment_url,
				'inReplyTo' => $parent_note_id,
				'to' => ['https://www.w3.org/ns/activitystreams#Public'],
				'cc' => [$followers_url],
			]);

			$this->sendActivityResponse($note);
			return;
		}

		// 게시물 Note
		if ($document_srl > 0)
		{
			$oDocument = \DocumentModel::getDocument($document_srl);
			if (!$oDocument || !$oDocument->document_srl)
			{
				$this->sendJsonResponse(['error' => 'Not found'], 404);
				return;
			}

			// 공개 글만 처리
			$status = $oDocument->status ?? '';
			if ($status !== 'PUBLIC' && $status !== '')
			{
				$this->sendJsonResponse(['error' => 'Not found'], 404);
				return;
			}

			// 비공개 게시판 게시물 제외
			if (!ActorModel::isModulePubliclyAccessible($oDocument->module_srl))
			{
				$this->sendJsonResponse(['error' => 'Not found'], 404);
				return;
			}

			$mid = ModuleModel::getMidByModuleSrl($oDocument->module_srl);
			$document_url = $site_url . '?mid=' . urlencode($mid) . '&document_srl=' . $document_srl;

			$title = $oDocument->title ?? '';
			$content = $oDocument->content ?? '';

			$content_text = strip_tags($content);
			if (mb_strlen($content_text) > 500)
			{
				$content_text = mb_substr($content_text, 0, 497) . '...';
			}

			$html_content = '<p><strong>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</strong></p>';
			$html_content .= '<p>' . htmlspecialchars($content_text, ENT_QUOTES, 'UTF-8') . '</p>';
			$html_content .= '<p><a href="' . htmlspecialchars($document_url, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($document_url, ENT_QUOTES, 'UTF-8') . '</a></p>';

			$note_id = ActorModel::getNoteUrl($actor->preferred_username, $document_srl);
			$published = self::formatRegdateToIso($oDocument->regdate ?? '');

			$note = Type::create('Note', [
				'@context' => 'https://www.w3.org/ns/activitystreams',
				'id' => $note_id,
				'published' => $published,
				'attributedTo' => $actor_url,
				'content' => $html_content,
				'url' => $document_url,
				'to' => ['https://www.w3.org/ns/activitystreams#Public'],
				'cc' => [$followers_url],
			]);

			// 수정일이 있고 등록일과 다르면 updated 필드 추가
			if (!empty($oDocument->last_update) && ($oDocument->last_update ?? '') !== ($oDocument->regdate ?? ''))
			{
				$note->set('updated', self::formatRegdateToIso($oDocument->last_update));
			}

			$this->sendActivityResponse($note);
			return;
		}

		$this->sendJsonResponse(['error' => 'Missing document_srl or comment_srl'], 400);
	}

	/**
	 * Inbox 엔드포인트 (GET/POST)
	 * GET: Inbox 컬렉션 반환
	 * POST: Follow/Undo 요청 처리
	 */
	public function procActivitypubInbox()
	{
		$method = $_SERVER['REQUEST_METHOD'] ?? 'POST';
		self::debugLog('--- procActivitypubInbox START (method: ' . $method . ') ---');

		// GET 요청: Inbox 컬렉션 반환
		if ($method === 'GET')
		{
			// Authorized Fetch 모드일 경우 HTTP Signature 검증
			if (!$this->checkAuthorizedFetch())
			{
				return;
			}

			$preferred_username = Context::get('preferred_username');
			if (!$preferred_username)
			{
				self::debugLog('procActivitypubInbox GET: Missing username');
				$this->sendJsonResponse(['error' => 'Missing username'], 400);
				return;
			}

			$actor = ActorModel::getActiveActorByPreferredUsername($preferred_username);
			if (!$actor)
			{
				self::debugLog('procActivitypubInbox GET: Unknown user: ' . $preferred_username);
				$this->sendJsonResponse(['error' => 'Unknown user'], 404);
				return;
			}

			$inbox_url = ActorModel::getInboxUrl($actor->preferred_username);

			$inboxCollection = Type::create('OrderedCollection', [
				'@context' => 'https://www.w3.org/ns/activitystreams',
				'id' => $inbox_url,
				'totalItems' => 0,
				'orderedItems' => [],
			]);

			$this->sendActivityResponse($inboxCollection);
			return;
		}

		// POST 요청: Activity 처리
		$preferred_username = Context::get('preferred_username');
		if (!$preferred_username)
		{
			// Rhymix의 Context가 POST 요청에서 쿼리 스트링 파라미터를 가져오지 못하는 경우를 대비한 폴백
			$preferred_username = $_GET['preferred_username'] ?? '';
		}
		if (!$preferred_username)
		{
			self::debugLog('procActivitypubInbox POST: Missing username');
			$this->sendJsonResponse(['error' => 'Missing username'], 400);
			return;
		}

		$actor = ActorModel::getActiveActorByPreferredUsername($preferred_username);
		if (!$actor)
		{
			self::debugLog('procActivitypubInbox POST: Unknown user: ' . $preferred_username);
			$this->sendJsonResponse(['error' => 'Unknown user'], 404);
			return;
		}

		self::debugLog('procActivitypubInbox POST: Actor found: ' . $actor->preferred_username . ' (actor_srl: ' . $actor->actor_srl . ')');

		// POST body 읽기
		$raw_body = file_get_contents('php://input');
		if (!$raw_body)
		{
			self::debugLog('procActivitypubInbox POST: Empty body');
			$this->sendJsonResponse(['error' => 'Empty body'], 400);
			return;
		}

		self::debugLog('procActivitypubInbox POST body (first 500 chars): ' . substr($raw_body, 0, 500));

		try
		{
			$payload = Type::fromJson($raw_body);
		}
		catch (\Exception $e)
		{
			self::debugLog('procActivitypubInbox POST: Invalid JSON: ' . $e->getMessage());
			$this->sendJsonResponse(['error' => 'Invalid JSON'], 400);
			return;
		}

		$type = $payload->type;
		self::debugLog('procActivitypubInbox POST: Activity type: ' . $type);

		switch ($type)
		{
			case 'Follow':
				$this->handleFollow($actor, $payload);
				break;

			case 'Undo':
				$this->handleUndo($actor, $payload);
				break;

			default:
				self::debugLog('procActivitypubInbox POST: Unhandled activity type: ' . $type);
				$this->sendJsonResponse(['status' => 'accepted'], 202);
				break;
		}
	}

	/**
	 * Follow 요청 처리
	 *
	 * @param object $actor
	 * @param \ActivityPhp\Type\AbstractObject $payload
	 */
	protected function handleFollow($actor, $payload)
	{
		self::debugLog('--- handleFollow START ---');
		self::debugLog('handleFollow: Local actor: ' . $actor->preferred_username . ' (actor_srl: ' . $actor->actor_srl . ')');

		$follower_actor_url = $payload->actor;
		self::debugLog('handleFollow: Follower actor URL: ' . ($follower_actor_url ?: '(empty)'));
		if (!$follower_actor_url || !filter_var($follower_actor_url, FILTER_VALIDATE_URL))
		{
			self::debugLog('handleFollow: FAIL - Invalid actor URL');
			$this->sendJsonResponse(['error' => 'Invalid actor'], 400);
			return;
		}

		// 원격 Actor 정보 가져오기 (서명된 요청으로 조회)
		$remote_actor = $this->fetchRemoteActor($follower_actor_url, $actor);
		if (!$remote_actor)
		{
			self::debugLog('handleFollow: FAIL - Cannot fetch remote actor');
			$this->sendJsonResponse(['error' => 'Cannot fetch remote actor'], 400);
			return;
		}

		$follower_inbox_url = $remote_actor['inbox'] ?? '';
		$follower_shared_inbox_url = $remote_actor['endpoints']['sharedInbox'] ?? '';
		self::debugLog('handleFollow: Follower inbox: ' . ($follower_inbox_url ?: '(empty)'));
		self::debugLog('handleFollow: Follower shared inbox: ' . ($follower_shared_inbox_url ?: '(empty)'));

		if (!$follower_inbox_url)
		{
			self::debugLog('handleFollow: FAIL - Remote actor has no inbox');
			$this->sendJsonResponse(['error' => 'Remote actor has no inbox'], 400);
			return;
		}

		// 팔로워 추가
		ActorModel::addFollower(
			$actor->actor_srl,
			$follower_actor_url,
			$follower_inbox_url,
			$follower_shared_inbox_url
		);
		self::debugLog('handleFollow: Follower added to DB');

		// Accept 응답 전송
		$actor_url = ActorModel::getActorUrl($actor->preferred_username);
		$accept = Type::create('Accept', [
			'@context' => 'https://www.w3.org/ns/activitystreams',
			'id' => $actor_url . '/accept/' . time(),
			'actor' => $actor_url,
			'object' => $payload->toArray(),
		]);

		$body = $accept->toJson(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		self::debugLog('handleFollow: Sending Accept to: ' . $follower_inbox_url);
		self::debugLog('handleFollow: Accept body (first 500 chars): ' . substr($body, 0, 500));
		$result = $this->sendSignedRequest($actor, $follower_inbox_url, $body);
		self::debugLog('handleFollow: sendSignedRequest result: ' . ($result ? 'SUCCESS' : 'FAIL'));

		self::debugLog('--- handleFollow END ---');
		$this->sendJsonResponse(['status' => 'accepted'], 202);
	}

	/**
	 * Undo 요청 처리 (Unfollow)
	 *
	 * @param object $actor
	 * @param \ActivityPhp\Type\AbstractObject $payload
	 */
	protected function handleUndo($actor, $payload)
	{
		self::debugLog('--- handleUndo START ---');
		self::debugLog('handleUndo: Local actor: ' . $actor->preferred_username . ' (actor_srl: ' . $actor->actor_srl . ')');

		$object = $payload->object;
		if (!$object || !($object instanceof \ActivityPhp\Type\AbstractObject) || $object->type !== 'Follow')
		{
			self::debugLog('handleUndo: Not an Undo Follow (object type: ' . ($object instanceof \ActivityPhp\Type\AbstractObject ? $object->type : gettype($object)) . ')');
			$this->sendJsonResponse(['status' => 'accepted'], 202);
			return;
		}

		$follower_actor_url = $payload->actor;
		self::debugLog('handleUndo: Follower actor URL: ' . ($follower_actor_url ?: '(empty)'));
		if ($follower_actor_url)
		{
			ActorModel::removeFollower($actor->actor_srl, $follower_actor_url);
			self::debugLog('handleUndo: Follower removed from DB');
		}

		self::debugLog('--- handleUndo END ---');
		$this->sendJsonResponse(['status' => 'accepted'], 202);
	}

	/**
	 * 원격 Actor 정보 가져오기
	 * 서명된 HTTP GET 요청을 사용하여 Authorized Fetch가 활성화된 서버도 지원
	 *
	 * @param string $url
	 * @param object|null $signingActor 서명에 사용할 로컬 Actor (null이면 아무 Actor 사용)
	 * @return array|null
	 */
	protected function fetchRemoteActor($url, $signingActor = null)
	{
		self::debugLog('--- fetchRemoteActor START ---');
		self::debugLog('Fetching URL: ' . $url);

		// 서명에 사용할 Actor 결정
		if (!$signingActor)
		{
			// URL 컨텍스트에서 preferred_username 가져오기 시도
			$preferred_username = Context::get('preferred_username');
			if (!$preferred_username)
			{
				$preferred_username = $_GET['preferred_username'] ?? '';
			}
			if ($preferred_username)
			{
				$signingActor = ActorModel::getActiveActorByPreferredUsername($preferred_username);
			}

			// 그래도 없으면 아무 Actor나 가져오기
			if (!$signingActor)
			{
				$actorList = ActorModel::getActorList(1);
				if ($actorList->toBool() && !empty($actorList->data))
				{
					$actors = is_array($actorList->data) ? $actorList->data : [$actorList->data];
					foreach ($actors as $a)
					{
						if (($a->is_deleted ?? 'N') !== 'Y' && !empty($a->private_key))
						{
							$signingActor = $a;
							break;
						}
					}
				}
			}
		}

		if ($signingActor)
		{
			self::debugLog('fetchRemoteActor: Signing with actor: ' . $signingActor->preferred_username);
		}
		else
		{
			self::debugLog('fetchRemoteActor: WARNING - No signing actor available, sending unsigned request');
		}

		// HTTP 요청 헤더 구성
		$headers = [
			'Accept: application/activity+json, application/ld+json',
		];

		// 서명 Actor가 있으면 HTTP Signature 추가
		if ($signingActor && !empty($signingActor->private_key))
		{
			$parsed = parse_url($url);
			$host = $parsed['host'] ?? '';
			$path = $parsed['path'] ?? '/';
			if (!empty($parsed['query']))
			{
				$path .= '?' . $parsed['query'];
			}

			$date = gmdate('D, d M Y H:i:s \G\M\T');

			$actor_url = ActorModel::getActorUrl($signingActor->preferred_username);
			$key_id = $actor_url . '#main-key';

			// GET 요청에는 Digest가 없음
			$signing_string = "(request-target): get " . $path . "\n";
			$signing_string .= "host: " . $host . "\n";
			$signing_string .= "date: " . $date;

			$private_key = openssl_pkey_get_private($signingActor->private_key);
			if ($private_key)
			{
				$signature = '';
				$success = openssl_sign($signing_string, $signature, $private_key, OPENSSL_ALGO_SHA256);
				if ($success)
				{
					$signature_b64 = base64_encode($signature);
					$signature_header = 'keyId="' . $key_id . '",algorithm="rsa-sha256",headers="(request-target) host date",signature="' . $signature_b64 . '"';

					$headers[] = 'Host: ' . $host;
					$headers[] = 'Date: ' . $date;
					$headers[] = 'Signature: ' . $signature_header;
					self::debugLog('fetchRemoteActor: HTTP Signature added');
				}
				else
				{
					self::debugLog('fetchRemoteActor: WARNING - openssl_sign failed');
				}
			}
			else
			{
				self::debugLog('fetchRemoteActor: WARNING - openssl_pkey_get_private failed');
			}
		}

		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
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
		$effective_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
		curl_close($ch);

		self::debugLog('fetchRemoteActor HTTP code: ' . $http_code);
		self::debugLog('fetchRemoteActor effective URL: ' . $effective_url);
		if ($curl_error)
		{
			self::debugLog('fetchRemoteActor cURL error: ' . $curl_error);
		}
		self::debugLog('fetchRemoteActor response (first 500 chars): ' . substr($response ?: '(empty)', 0, 500));

		if ($http_code !== 200 || !$response)
		{
			self::debugLog('FAIL: fetchRemoteActor got HTTP ' . $http_code . ' or empty response');
			return null;
		}

		$data = json_decode($response, true);
		self::debugLog('fetchRemoteActor json_decode result is_array: ' . (is_array($data) ? 'YES' : 'NO'));
		if (is_array($data))
		{
			self::debugLog('fetchRemoteActor response keys: ' . implode(', ', array_keys($data)));
		}
		return is_array($data) ? $data : null;
	}

	/**
	 * HTTP Signature로 서명된 요청 전송
	 *
	 * @param object $actor
	 * @param string $url
	 * @param string $body
	 * @return bool
	 */
	protected function sendSignedRequest($actor, $url, $body)
	{
		self::debugLog('--- sendSignedRequest START ---');
		self::debugLog('sendSignedRequest: Target URL: ' . $url);
		self::debugLog('sendSignedRequest: Signing actor: ' . $actor->preferred_username);

		$parsed = parse_url($url);
		if (!$parsed || !isset($parsed['host']))
		{
			self::debugLog('sendSignedRequest: FAIL - Invalid URL');
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

		$signing_string = "(request-target): post " . $path . "\n";
		$signing_string .= "host: " . $host . "\n";
		$signing_string .= "date: " . $date . "\n";
		$signing_string .= "digest: " . $digest;

		$private_key = openssl_pkey_get_private($actor->private_key);
		if (!$private_key)
		{
			self::debugLog('sendSignedRequest: FAIL - openssl_pkey_get_private failed');
			return false;
		}

		$signature = '';
		$success = openssl_sign($signing_string, $signature, $private_key, OPENSSL_ALGO_SHA256);
		if (!$success)
		{
			self::debugLog('sendSignedRequest: FAIL - openssl_sign failed');
			return false;
		}

		$signature_b64 = base64_encode($signature);
		$signature_header = 'keyId="' . $key_id . '",algorithm="rsa-sha256",headers="(request-target) host date digest",signature="' . $signature_b64 . '"';

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

		self::debugLog('sendSignedRequest: HTTP code: ' . $http_code);
		if ($curl_error)
		{
			self::debugLog('sendSignedRequest: cURL error: ' . $curl_error);
		}
		self::debugLog('sendSignedRequest: Response (first 500 chars): ' . substr($response ?: '(empty)', 0, 500));

		$result = ($http_code >= 200 && $http_code < 300);
		self::debugLog('sendSignedRequest: Result: ' . ($result ? 'SUCCESS' : 'FAIL'));
		self::debugLog('--- sendSignedRequest END ---');
		return $result;
	}

	/**
	 * Followers 컬렉션 엔드포인트
	 */
	public function dispActivitypubFollowers()
	{
		// Authorized Fetch 모드일 경우 HTTP Signature 검증
		if (!$this->checkAuthorizedFetch())
		{
			return;
		}

		$preferred_username = Context::get('preferred_username');
		if (!$preferred_username)
		{
			$this->sendJsonResponse(['error' => 'Missing username'], 400);
			return;
		}

		$actor = ActorModel::getActiveActorByPreferredUsername($preferred_username);
		if (!$actor)
		{
			$this->sendJsonResponse(['error' => 'Unknown user'], 404);
			return;
		}

		$followers_url = ActorModel::getFollowersUrl($actor->preferred_username);

		// 팔로워 수 가져오기
		$followers_output = ActorModel::getFollowers($actor->actor_srl);
		$total_items = 0;
		$follower_urls = [];
		if ($followers_output->toBool() && !empty($followers_output->data))
		{
			$followers = is_array($followers_output->data) ? $followers_output->data : [$followers_output->data];
			$total_items = count($followers);

			// 팔로워 목록 비공개가 아닌 경우에만 URL 목록 포함
			if (($actor->hide_followers ?? 'N') !== 'Y')
			{
				foreach ($followers as $follower)
				{
					$follower_urls[] = $follower->follower_actor_url;
				}
			}
		}

		$collection_data = [
			'@context' => 'https://www.w3.org/ns/activitystreams',
			'id' => $followers_url,
			'totalItems' => $total_items,
		];

		if (!empty($follower_urls))
		{
			$collection_data['orderedItems'] = $follower_urls;
		}

		$response = Type::create('OrderedCollection', $collection_data);

		$this->sendActivityResponse($response);
	}

	/**
	 * Following 컬렉션 엔드포인트
	 */
	public function dispActivitypubFollowing()
	{
		// Authorized Fetch 모드일 경우 HTTP Signature 검증
		if (!$this->checkAuthorizedFetch())
		{
			return;
		}

		$preferred_username = Context::get('preferred_username');
		if (!$preferred_username)
		{
			$this->sendJsonResponse(['error' => 'Missing username'], 400);
			return;
		}

		$actor = ActorModel::getActiveActorByPreferredUsername($preferred_username);
		if (!$actor)
		{
			$this->sendJsonResponse(['error' => 'Unknown user'], 404);
			return;
		}

		$following_url = ActorModel::getFollowingUrl($actor->preferred_username);

		// 이 서버에서는 다른 Actor를 팔로우하지 않으므로 항상 빈 컬렉션
		$response = Type::create('OrderedCollection', [
			'@context' => 'https://www.w3.org/ns/activitystreams',
			'id' => $following_url,
			'totalItems' => 0,
		]);

		$this->sendActivityResponse($response);
	}

	/**
	 * 공유 Inbox 엔드포인트 (GET/POST)
	 * GET: 빈 OrderedCollection 반환
	 * POST: Activity 처리 (대상 Actor를 payload에서 판별)
	 */
	public function procActivitypubSharedInbox()
	{
		$method = $_SERVER['REQUEST_METHOD'] ?? 'POST';
		self::debugLog('--- procActivitypubSharedInbox START (method: ' . $method . ') ---');

		// GET 요청: 빈 OrderedCollection 반환
		if ($method === 'GET')
		{
			// Authorized Fetch 모드일 경우 HTTP Signature 검증
			if (!$this->checkAuthorizedFetch())
			{
				return;
			}

			$shared_inbox_url = ActorModel::getSharedInboxUrl();

			$sharedInboxCollection = Type::create('OrderedCollection', [
				'@context' => 'https://www.w3.org/ns/activitystreams',
				'id' => $shared_inbox_url,
				'totalItems' => 0,
				'orderedItems' => [],
			]);

			$this->sendActivityResponse($sharedInboxCollection);
			return;
		}

		// POST 요청: Activity 처리
		$raw_body = file_get_contents('php://input');
		if (!$raw_body)
		{
			self::debugLog('procActivitypubSharedInbox POST: Empty body');
			$this->sendJsonResponse(['error' => 'Empty body'], 400);
			return;
		}

		self::debugLog('procActivitypubSharedInbox POST body (first 500 chars): ' . substr($raw_body, 0, 500));

		try
		{
			$payload = Type::fromJson($raw_body);
		}
		catch (\Exception $e)
		{
			self::debugLog('procActivitypubSharedInbox POST: Invalid JSON: ' . $e->getMessage());
			$this->sendJsonResponse(['error' => 'Invalid JSON'], 400);
			return;
		}

		// payload에서 대상 Actor 결정
		$actor = $this->resolveTargetActorFromPayload($payload);
		if (!$actor)
		{
			self::debugLog('procActivitypubSharedInbox POST: Could not resolve target actor from payload');
			$this->sendJsonResponse(['status' => 'accepted'], 202);
			return;
		}

		self::debugLog('procActivitypubSharedInbox POST: Resolved target actor: ' . $actor->preferred_username . ' (actor_srl: ' . $actor->actor_srl . ')');

		$type = $payload->type;
		self::debugLog('procActivitypubSharedInbox POST: Activity type: ' . $type);

		switch ($type)
		{
			case 'Follow':
				$this->handleFollow($actor, $payload);
				break;

			case 'Undo':
				$this->handleUndo($actor, $payload);
				break;

			default:
				self::debugLog('procActivitypubSharedInbox POST: Unhandled activity type: ' . $type);
				$this->sendJsonResponse(['status' => 'accepted'], 202);
				break;
		}
	}

	/**
	 * Activity payload에서 대상 Actor를 결정
	 *
	 * @param \ActivityPhp\Type\AbstractObject $payload
	 * @return object|null
	 */
	protected function resolveTargetActorFromPayload($payload)
	{
		self::debugLog('--- resolveTargetActorFromPayload START ---');
		$type = $payload->type ?? '';
		$target_url = '';

		// Follow: object가 대상 Actor URL
		if ($type === 'Follow')
		{
			$object = $payload->object;
			$target_url = is_string($object) ? $object : '';
			self::debugLog('resolveTargetActorFromPayload: Follow target URL: ' . ($target_url ?: '(empty)'));
		}
		// Undo: 내부 object에서 대상 찾기
		elseif ($type === 'Undo' && $payload->object instanceof \ActivityPhp\Type\AbstractObject)
		{
			$inner = $payload->object;
			if ($inner->type === 'Follow')
			{
				$inner_object = $inner->object;
				$target_url = is_string($inner_object) ? $inner_object : '';
				self::debugLog('resolveTargetActorFromPayload: Undo Follow target URL: ' . ($target_url ?: '(empty)'));
			}
			else
			{
				self::debugLog('resolveTargetActorFromPayload: Undo inner type is not Follow: ' . $inner->type);
			}
		}
		else
		{
			self::debugLog('resolveTargetActorFromPayload: Unhandled type: ' . $type);
		}

		if (!$target_url)
		{
			self::debugLog('resolveTargetActorFromPayload: No target URL found');
			return null;
		}

		// URL에서 preferred_username 추출
		$query_string = parse_url($target_url, PHP_URL_QUERY);
		if (!$query_string)
		{
			self::debugLog('resolveTargetActorFromPayload: No query string in target URL');
			return null;
		}

		parse_str($query_string, $params);
		$preferred_username = $params['preferred_username'] ?? '';
		if (!$preferred_username)
		{
			self::debugLog('resolveTargetActorFromPayload: No preferred_username in query string');
			return null;
		}

		self::debugLog('resolveTargetActorFromPayload: Resolved preferred_username: ' . $preferred_username);
		$actor = ActorModel::getActiveActorByPreferredUsername($preferred_username);
		self::debugLog('resolveTargetActorFromPayload: Actor found: ' . ($actor ? 'YES' : 'NO'));
		return $actor;
	}

	/**
	 * Authorized Fetch (Secure Mode) 검증
	 * 설정이 활성화된 경우 GET 요청에 대해 HTTP Signature 검증을 수행
	 *
	 * @return bool true이면 통과, false이면 응답이 이미 전송됨
	 */
	protected function checkAuthorizedFetch()
	{
		self::debugLog('--- checkAuthorizedFetch START ---');
		$config = ConfigModel::getConfig();
		$authorizedFetchSetting = $config->authorized_fetch ?? 'N';
		self::debugLog('authorized_fetch config value: ' . $authorizedFetchSetting);
		if ($authorizedFetchSetting !== 'Y')
		{
			self::debugLog('Authorized fetch is DISABLED - allowing request');
			return true;
		}

		self::debugLog('Authorized fetch is ENABLED - verifying signature...');
		if (!$this->verifyHttpSignature())
		{
			self::debugLog('verifyHttpSignature() returned FALSE - sending 401');
			$this->sendJsonResponse(['error' => 'Request not signed or signature verification failed'], 401);
			return false;
		}

		self::debugLog('verifyHttpSignature() returned TRUE - request authorized');
		return true;
	}

	/**
	 * HTTP Signature 검증 (draft-cavage-http-signatures-12 형식)
	 *
	 * landrok/activitypub 라이브러리의 서명 검증은 원격 Actor 공개키 조회 시
	 * 서명 없는 HTTP 요청을 보내기 때문에, 원격 서버가 Authorized Fetch를 활성화한 경우
	 * 공개키 조회에 실패합니다. 이를 해결하기 위해 자체 구현을 사용합니다.
	 *
	 * @return bool
	 */
	protected function verifyHttpSignature()
	{
		self::debugLog('--- verifyHttpSignature START ---');
		try
		{
			// Signature 헤더 읽기
			$signatureHeader = $_SERVER['HTTP_SIGNATURE'] ?? '';
			self::debugLog('Raw HTTP_SIGNATURE header: ' . ($signatureHeader ?: '(empty)'));
			if (!$signatureHeader)
			{
				self::debugLog('FAIL: No Signature header found');
				return false;
			}

			// Signature 헤더 파싱 (draft-cavage-http-signatures-12 형식)
			self::debugLog('Attempting regex match with pattern: ' . self::SIGNATURE_HEADER_PATTERN);
			if (!preg_match(self::SIGNATURE_HEADER_PATTERN, $signatureHeader, $matches))
			{
				self::debugLog('FAIL: Signature header does not match expected pattern');
				self::debugLog('preg_last_error: ' . preg_last_error());
				return false;
			}

			$keyId = $matches['keyId'] ?? '';
			$headers = $matches['headers'] ?? 'date';
			$signatureValue = $matches['signature'] ?? '';

			self::debugLog('Parsed keyId: ' . $keyId);
			self::debugLog('Parsed headers: ' . $headers);
			self::debugLog('Parsed signature (first 60 chars): ' . substr($signatureValue, 0, 60) . '...');

			if (!$keyId || !$signatureValue)
			{
				self::debugLog('FAIL: keyId or signature is empty');
				return false;
			}

			// keyId에서 Actor URL 추출 (fragment 제거)
			$actorUrl = preg_replace('/#.*$/', '', $keyId);
			self::debugLog('Actor URL (fragment removed): ' . $actorUrl);

			// 원격 Actor의 공개키 조회
			self::debugLog('Fetching remote actor from: ' . $actorUrl);
			$remoteActor = $this->fetchRemoteActor($actorUrl);
			if (!$remoteActor || !isset($remoteActor['publicKey']['publicKeyPem']))
			{
				self::debugLog('FAIL: Could not fetch remote actor or publicKey not found');
				self::debugLog('remoteActor is null: ' . ($remoteActor === null ? 'YES' : 'NO'));
				if ($remoteActor)
				{
					self::debugLog('remoteActor keys: ' . implode(', ', array_keys($remoteActor)));
					self::debugLog('has publicKey: ' . (isset($remoteActor['publicKey']) ? 'YES' : 'NO'));
					if (isset($remoteActor['publicKey']))
					{
						self::debugLog('publicKey content: ' . json_encode($remoteActor['publicKey']));
					}
				}
				return false;
			}

			$publicKeyPem = $remoteActor['publicKey']['publicKeyPem'];
			self::debugLog('Got publicKeyPem (first 80 chars): ' . substr($publicKeyPem, 0, 80) . '...');

			// 서명 문자열 재구성
			$headerList = explode(' ', $headers);
			$signingParts = [];
			self::debugLog('Header list to sign: ' . json_encode($headerList));

			foreach ($headerList as $headerName)
			{
				if ($headerName === '(request-target)')
				{
					$method = strtolower($_SERVER['REQUEST_METHOD'] ?? 'GET');
					$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
					$signingParts[] = '(request-target): ' . $method . ' ' . $requestUri;
					self::debugLog('Signing part (request-target): ' . $method . ' ' . $requestUri);
				}
				elseif ($headerName === 'host')
				{
					$host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
					$signingParts[] = 'host: ' . $host;
					self::debugLog('Signing part host: ' . $host);
				}
				else
				{
					$serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $headerName));
					self::debugLog('Looking for header "' . $headerName . '" as $_SERVER["' . $serverKey . '"]');
					if (isset($_SERVER[$serverKey]))
					{
						$signingParts[] = $headerName . ': ' . $_SERVER[$serverKey];
						self::debugLog('Signing part ' . $headerName . ': ' . $_SERVER[$serverKey]);
					}
					else
					{
						self::debugLog('WARNING: Header "' . $headerName . '" ($_SERVER["' . $serverKey . '"]) NOT FOUND in $_SERVER');
					}
				}
			}

			$signingString = implode("\n", $signingParts);
			self::debugLog('Final signing string (repr): ' . json_encode($signingString));

			// 서명 검증
			$publicKey = openssl_pkey_get_public($publicKeyPem);
			if (!$publicKey)
			{
				self::debugLog('FAIL: openssl_pkey_get_public() returned false');
				self::debugLog('OpenSSL error: ' . openssl_error_string());
				return false;
			}
			self::debugLog('Public key loaded successfully');

			$decodedSignature = base64_decode($signatureValue, true);
			if ($decodedSignature === false)
			{
				self::debugLog('FAIL: base64_decode of signature failed');
				return false;
			}
			self::debugLog('Decoded signature length: ' . strlen($decodedSignature) . ' bytes');

			$verifyResult = openssl_verify($signingString, $decodedSignature, $publicKey, OPENSSL_ALGO_SHA256);
			self::debugLog('openssl_verify() result: ' . $verifyResult . ' (1=success, 0=fail, -1=error)');
			if ($verifyResult !== 1)
			{
				self::debugLog('OpenSSL error (if any): ' . openssl_error_string());
			}
			$result = $verifyResult === 1;

			// PHP 7.x 호환: 리소스 해제
			if (PHP_MAJOR_VERSION < 8)
			{
				openssl_pkey_free($publicKey);
			}

			self::debugLog('verifyHttpSignature returning: ' . ($result ? 'TRUE' : 'FALSE'));
			return $result;
		}
		catch (\Exception $e)
		{
			self::debugLog('EXCEPTION in verifyHttpSignature: ' . $e->getMessage());
			self::debugLog('Exception trace: ' . $e->getTraceAsString());
			return false;
		}
	}

	/**
	 * Rhymix의 YmdHis 형식 날짜를 ISO 8601 형식으로 변환
	 *
	 * @param string $regdate
	 * @return string
	 */
	protected static function formatRegdateToIso($regdate)
	{
		if (!$regdate)
		{
			return date('c');
		}
		$dt = \DateTime::createFromFormat('YmdHis', $regdate);
		return $dt ? $dt->format('c') : date('c');
	}

	/**
	 * ActivityPub Type 객체를 JSON 응답으로 전송
	 *
	 * @param \ActivityPhp\Type\AbstractObject $type
	 * @param int $status_code
	 */
	protected function sendActivityResponse($type, $status_code = 200)
	{
		http_response_code($status_code);
		header('Content-Type: application/activity+json; charset=utf-8');
		echo $type->toJson(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		exit;
	}

	/**
	 * JSON 응답 전송
	 *
	 * @param array $data
	 * @param int $status_code
	 * @param string $content_type
	 */
	protected function sendJsonResponse($data, $status_code = 200, $content_type = 'application/json')
	{
		http_response_code($status_code);
		header('Content-Type: ' . $content_type . '; charset=utf-8');
		echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		exit;
	}
}
