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
	 * HTTP Signature의 최대 유효 시간 (초 단위, 12시간)
	 */
	const HTTP_SIGNATURE_MAX_AGE = 43200;

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

		// 간단한 빈 Outbox 응답 (OrderedCollection)
		$outboxCollection = Type::create('OrderedCollection', [
			'@context' => 'https://www.w3.org/ns/activitystreams',
			'id' => $outbox_url,
			'totalItems' => 0,
			'orderedItems' => [],
		]);

		$this->sendActivityResponse($outboxCollection);
	}

	/**
	 * Inbox 엔드포인트 (GET/POST)
	 * GET: Inbox 컬렉션 반환
	 * POST: Follow/Undo 요청 처리
	 */
	public function procActivitypubInbox()
	{
		$method = $_SERVER['REQUEST_METHOD'] ?? 'POST';

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
				$this->sendJsonResponse(['error' => 'Missing username'], 400);
				return;
			}

			$actor = ActorModel::getActiveActorByPreferredUsername($preferred_username);
			if (!$actor)
			{
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
			$this->sendJsonResponse(['error' => 'Missing username'], 400);
			return;
		}

		$actor = ActorModel::getActiveActorByPreferredUsername($preferred_username);
		if (!$actor)
		{
			$this->sendJsonResponse(['error' => 'Unknown user'], 404);
			return;
		}

		// POST body 읽기
		$raw_body = file_get_contents('php://input');
		if (!$raw_body)
		{
			$this->sendJsonResponse(['error' => 'Empty body'], 400);
			return;
		}

		try
		{
			$payload = Type::fromJson($raw_body);
		}
		catch (\Exception $e)
		{
			$this->sendJsonResponse(['error' => 'Invalid JSON'], 400);
			return;
		}

		$type = $payload->type;

		switch ($type)
		{
			case 'Follow':
				$this->handleFollow($actor, $payload);
				break;

			case 'Undo':
				$this->handleUndo($actor, $payload);
				break;

			default:
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
		$follower_actor_url = $payload->actor;
		if (!$follower_actor_url || !filter_var($follower_actor_url, FILTER_VALIDATE_URL))
		{
			$this->sendJsonResponse(['error' => 'Invalid actor'], 400);
			return;
		}

		// 원격 Actor 정보 가져오기
		$remote_actor = $this->fetchRemoteActor($follower_actor_url);
		if (!$remote_actor)
		{
			$this->sendJsonResponse(['error' => 'Cannot fetch remote actor'], 400);
			return;
		}

		$follower_inbox_url = $remote_actor['inbox'] ?? '';
		$follower_shared_inbox_url = $remote_actor['endpoints']['sharedInbox'] ?? '';

		if (!$follower_inbox_url)
		{
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

		// Accept 응답 전송
		$actor_url = ActorModel::getActorUrl($actor->preferred_username);
		$accept = Type::create('Accept', [
			'@context' => 'https://www.w3.org/ns/activitystreams',
			'id' => $actor_url . '/accept/' . time(),
			'actor' => $actor_url,
			'object' => $payload->toArray(),
		]);

		$body = $accept->toJson(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$this->sendSignedRequest($actor, $follower_inbox_url, $body);

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
		$object = $payload->object;
		if (!$object || !($object instanceof \ActivityPhp\Type\AbstractObject) || $object->type !== 'Follow')
		{
			$this->sendJsonResponse(['status' => 'accepted'], 202);
			return;
		}

		$follower_actor_url = $payload->actor;
		if ($follower_actor_url)
		{
			ActorModel::removeFollower($actor->actor_srl, $follower_actor_url);
		}

		$this->sendJsonResponse(['status' => 'accepted'], 202);
	}

	/**
	 * 원격 Actor 정보 가져오기
	 *
	 * @param string $url
	 * @return array|null
	 */
	protected function fetchRemoteActor($url)
	{
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => [
				'Accept: application/activity+json, application/ld+json',
			],
			CURLOPT_TIMEOUT => 10,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS => 3,
			CURLOPT_USERAGENT => 'RhymixActivityPub/1.0',
		]);

		$response = curl_exec($ch);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($http_code !== 200 || !$response)
		{
			return null;
		}

		$data = json_decode($response, true);
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
		$parsed = parse_url($url);
		if (!$parsed || !isset($parsed['host']))
		{
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
			return false;
		}

		$signature = '';
		$success = openssl_sign($signing_string, $signature, $private_key, OPENSSL_ALGO_SHA256);
		if (!$success)
		{
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
		curl_close($ch);

		return ($http_code >= 200 && $http_code < 300);
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
		if ($followers_output->toBool() && !empty($followers_output->data))
		{
			$followers = is_array($followers_output->data) ? $followers_output->data : [$followers_output->data];
			$total_items = count($followers);
		}

		$response = Type::create('OrderedCollection', [
			'@context' => 'https://www.w3.org/ns/activitystreams',
			'id' => $followers_url,
			'totalItems' => $total_items,
		]);

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
			$this->sendJsonResponse(['error' => 'Empty body'], 400);
			return;
		}

		try
		{
			$payload = Type::fromJson($raw_body);
		}
		catch (\Exception $e)
		{
			$this->sendJsonResponse(['error' => 'Invalid JSON'], 400);
			return;
		}

		// payload에서 대상 Actor 결정
		$actor = $this->resolveTargetActorFromPayload($payload);
		if (!$actor)
		{
			$this->sendJsonResponse(['status' => 'accepted'], 202);
			return;
		}

		$type = $payload->type;

		switch ($type)
		{
			case 'Follow':
				$this->handleFollow($actor, $payload);
				break;

			case 'Undo':
				$this->handleUndo($actor, $payload);
				break;

			default:
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
		$type = $payload->type ?? '';
		$target_url = '';

		// Follow: object가 대상 Actor URL
		if ($type === 'Follow')
		{
			$object = $payload->object;
			$target_url = is_string($object) ? $object : '';
		}
		// Undo: 내부 object에서 대상 찾기
		elseif ($type === 'Undo' && $payload->object instanceof \ActivityPhp\Type\AbstractObject)
		{
			$inner = $payload->object;
			if ($inner->type === 'Follow')
			{
				$inner_object = $inner->object;
				$target_url = is_string($inner_object) ? $inner_object : '';
			}
		}

		if (!$target_url)
		{
			return null;
		}

		// URL에서 preferred_username 추출
		$query_string = parse_url($target_url, PHP_URL_QUERY);
		if (!$query_string)
		{
			return null;
		}

		parse_str($query_string, $params);
		$preferred_username = $params['preferred_username'] ?? '';
		if (!$preferred_username)
		{
			return null;
		}

		return ActorModel::getActiveActorByPreferredUsername($preferred_username);
	}

	/**
	 * Authorized Fetch (Secure Mode) 검증
	 * 설정이 활성화된 경우 GET 요청에 대해 HTTP Signature 검증을 수행
	 *
	 * @return bool true이면 통과, false이면 응답이 이미 전송됨
	 */
	protected function checkAuthorizedFetch()
	{
		$config = ConfigModel::getConfig();
		if (($config->authorized_fetch ?? 'N') !== 'Y')
		{
			return true;
		}

		if (!$this->verifyHttpSignature())
		{
			$this->sendJsonResponse(['error' => 'Request not signed or signature verification failed'], 401);
			return false;
		}

		return true;
	}

	/**
	 * HTTP Signature 검증
	 * 마스토돈 스펙에 따라 Signature 헤더를 파싱하고 서명을 검증
	 *
	 * @return bool
	 */
	protected function verifyHttpSignature()
	{
		$signature_header = $_SERVER['HTTP_SIGNATURE'] ?? '';
		if (!$signature_header)
		{
			return false;
		}

		// Signature 헤더 파싱: keyId, headers, signature
		$parts = [];
		if (preg_match_all('/(\w+)="([^"]*)"/', $signature_header, $matches, PREG_SET_ORDER))
		{
			foreach ($matches as $match)
			{
				$parts[$match[1]] = $match[2];
			}
		}

		$key_id = $parts['keyId'] ?? '';
		$headers_list = $parts['headers'] ?? 'date';
		$signature_b64 = $parts['signature'] ?? '';

		if (!$key_id || !$signature_b64)
		{
			return false;
		}

		// Date 헤더의 유효성 확인 (12시간 이내)
		$date_header = $_SERVER['HTTP_DATE'] ?? '';
		if ($date_header)
		{
			$request_time = strtotime($date_header);
			if ($request_time === false || abs(time() - $request_time) > self::HTTP_SIGNATURE_MAX_AGE)
			{
				return false;
			}
		}

		// 원격 Actor의 공개 키 가져오기
		$actor_url = preg_replace('/#.*$/', '', $key_id);
		$remote_actor = $this->fetchRemoteActor($actor_url);
		if (!$remote_actor || empty($remote_actor['publicKey']['publicKeyPem']))
		{
			return false;
		}

		// keyId가 일치하는지 확인
		$remote_key_id = $remote_actor['publicKey']['id'] ?? '';
		if ($remote_key_id && $remote_key_id !== $key_id)
		{
			return false;
		}

		$public_key_pem = $remote_actor['publicKey']['publicKeyPem'];

		// 서명 문자열 재구성
		$headers = explode(' ', $headers_list);
		$signing_parts = [];
		foreach ($headers as $header_name)
		{
			$header_name = strtolower(trim($header_name));
			if ($header_name === '(request-target)')
			{
				$method = strtolower($_SERVER['REQUEST_METHOD'] ?? 'get');
				$path = $_SERVER['REQUEST_URI'] ?? '/';
				$signing_parts[] = '(request-target): ' . $method . ' ' . $path;
			}
			elseif ($header_name === 'host')
			{
				$signing_parts[] = 'host: ' . ($_SERVER['HTTP_HOST'] ?? '');
			}
			elseif ($header_name === 'date')
			{
				$signing_parts[] = 'date: ' . ($date_header ?: '');
			}
			elseif ($header_name === 'digest')
			{
				$signing_parts[] = 'digest: ' . ($_SERVER['HTTP_DIGEST'] ?? '');
			}
			elseif ($header_name === 'content-type')
			{
				$signing_parts[] = 'content-type: ' . ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
			}
			else
			{
				$server_key = 'HTTP_' . strtoupper(str_replace('-', '_', $header_name));
				$signing_parts[] = $header_name . ': ' . ($_SERVER[$server_key] ?? '');
			}
		}
		$signing_string = implode("\n", $signing_parts);

		// 서명 검증 (RSA-SHA256)
		$decoded_signature = base64_decode($signature_b64);
		if ($decoded_signature === false)
		{
			return false;
		}

		$public_key = openssl_pkey_get_public($public_key_pem);
		if (!$public_key)
		{
			return false;
		}

		$result = openssl_verify($signing_string, $decoded_signature, $public_key, OPENSSL_ALGO_SHA256);
		return $result === 1;
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
