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

		// 각 Actor를 통해 팔로워에게 전송
		foreach ($actors as $actor)
		{
			// 비동기 큐가 사용 가능한 경우 큐에 추가
			if (self::isQueueAvailable())
			{
				$args = new \stdClass;
				$args->actor_srl = $actor->actor_srl;
				$args->document_srl = $obj->document_srl;
				$args->module_srl = $obj->module_srl;
				$args->title = $obj->title ?? '';
				$args->content = $obj->content ?? '';

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
		if (!$obj || !isset($obj->module_srl))
		{
			return;
		}

		// 댓글 AP 전송이 비활성화된 경우 제외
		$config = ConfigModel::getConfig();
		if (($config->send_comments ?? 'Y') !== 'Y')
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
			// 비동기 큐가 사용 가능한 경우 큐에 추가
			if (self::isQueueAvailable())
			{
				$args = new \stdClass;
				$args->actor_srl = $actor->actor_srl;
				$args->comment_srl = $obj->comment_srl;
				$args->document_srl = $obj->document_srl;
				$args->module_srl = $obj->module_srl;
				$args->content = $obj->content ?? '';

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
	 * moduleHandler.proc before 트리거
	 * WebFinger 요청을 가로채서 처리
	 *
	 * @param object $obj
	 */
	public function beforeModuleHandlerProc($obj)
	{
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
			echo json_encode(['error' => 'Missing resource parameter']);
			exit;
		}

		// acct:username@domain 형식 파싱
		if (strpos($resource, 'acct:') !== 0)
		{
			http_response_code(400);
			echo json_encode(['error' => 'Invalid resource format']);
			exit;
		}

		$acct = substr($resource, 5);
		$parts = explode('@', $acct);
		if (count($parts) !== 2)
		{
			http_response_code(400);
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
			echo json_encode(['error' => 'Unknown domain']);
			exit;
		}

		// Actor 찾기
		$actor = ActorModel::getActiveActorByPreferredUsername($username);
		if (!$actor)
		{
			http_response_code(404);
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
		return class_exists('Rhymix\\Framework\\Queue')
			&& function_exists('config')
			&& config('queue.enabled')
			&& !defined('RXQUEUE_CRON');
	}

	/**
	 * 게시물 배달 큐 태스크 핸들러
	 *
	 * @param object $args
	 * @param object|null $options
	 */
	public static function processDocumentDeliveryTask($args, $options = null)
	{
		self::debugLog('[processDocumentDeliveryTask] Called with actor_srl=' . ($args->actor_srl ?? 'null') . ', document_srl=' . ($args->document_srl ?? 'null') . ', module_srl=' . ($args->module_srl ?? 'null'));

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

		self::debugLog('[processDocumentDeliveryTask] Delivering document_srl=' . $args->document_srl . ' via actor=' . $actor->preferred_username);
		self::deliverDocumentToFollowers($actor, $args);
	}

	/**
	 * 댓글 배달 큐 태스크 핸들러
	 *
	 * @param object $args
	 * @param object|null $options
	 */
	public static function processCommentDeliveryTask($args, $options = null)
	{
		self::debugLog('[processCommentDeliveryTask] Called with actor_srl=' . ($args->actor_srl ?? 'null') . ', comment_srl=' . ($args->comment_srl ?? 'null') . ', document_srl=' . ($args->document_srl ?? 'null') . ', module_srl=' . ($args->module_srl ?? 'null'));

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

		self::debugLog('[processCommentDeliveryTask] Delivering comment_srl=' . $args->comment_srl . ' via actor=' . $actor->preferred_username);
		self::deliverCommentToFollowers($actor, $args);
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

		// HTML을 평문으로 변환 (간단한 처리)
		$content_text = strip_tags($content);
		if (mb_strlen($content_text) > 500)
		{
			$content_text = mb_substr($content_text, 0, 497) . '...';
		}

		// HTML 컨텐츠 생성
		$html_content = '<p><strong>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</strong></p>';
		$html_content .= '<p>' . htmlspecialchars($content_text, ENT_QUOTES, 'UTF-8') . '</p>';
		$html_content .= '<p><a href="' . htmlspecialchars($document_url, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($document_url, ENT_QUOTES, 'UTF-8') . '</a></p>';

		$published = date('c');
		$note_id = $actor_url . '/note/' . $document_srl;
		$followers_url = ActorModel::getFollowersUrl($actor->preferred_username);

		$note = Type::create('Note', [
			'id' => $note_id,
			'published' => $published,
			'attributedTo' => $actor_url,
			'content' => $html_content,
			'url' => $document_url,
			'to' => ['https://www.w3.org/ns/activitystreams#Public'],
			'cc' => [$followers_url],
		]);

		$activity = Type::create('Create', [
			'@context' => 'https://www.w3.org/ns/activitystreams',
			'id' => $note_id . '/activity',
			'actor' => $actor_url,
			'published' => $published,
			'to' => ['https://www.w3.org/ns/activitystreams#Public'],
			'cc' => [$followers_url],
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
		$content_text = strip_tags($content);
		if (mb_strlen($content_text) > 500)
		{
			$content_text = mb_substr($content_text, 0, 497) . '...';
		}

		$html_content = '<p>' . htmlspecialchars($content_text, ENT_QUOTES, 'UTF-8') . '</p>';
		$html_content .= '<p><a href="' . htmlspecialchars($comment_url, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($comment_url, ENT_QUOTES, 'UTF-8') . '</a></p>';

		$published = date('c');
		$note_id = $actor_url . '/comment/' . $comment_srl;
		$parent_note_id = $actor_url . '/note/' . $document_srl;
		$followers_url = ActorModel::getFollowersUrl($actor->preferred_username);

		$note = Type::create('Note', [
			'id' => $note_id,
			'published' => $published,
			'attributedTo' => $actor_url,
			'content' => $html_content,
			'url' => $comment_url,
			'inReplyTo' => $parent_note_id,
			'to' => ['https://www.w3.org/ns/activitystreams#Public'],
			'cc' => [$followers_url],
		]);

		$activity = Type::create('Create', [
			'@context' => 'https://www.w3.org/ns/activitystreams',
			'id' => $note_id . '/activity',
			'actor' => $actor_url,
			'published' => $published,
			'to' => ['https://www.w3.org/ns/activitystreams#Public'],
			'cc' => [$followers_url],
			'object' => $note->toArray(),
		]);

		self::debugLog('[deliverCommentToFollowers] Activity created: note_id=' . $note_id . ', calling deliverToFollowers');

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
