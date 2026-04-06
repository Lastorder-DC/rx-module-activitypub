<?php

namespace Rhymix\Modules\Activitypub\Controllers;

use Rhymix\Modules\Activitypub\Models\Actor as ActorModel;
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
	 * 초기화
	 */
	public function init()
	{
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

		$actor = ActorModel::getActorByPreferredUsername($username);
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
		$preferred_username = Context::get('preferred_username');
		if (!$preferred_username)
		{
			$this->sendJsonResponse(['error' => 'Missing username'], 400);
			return;
		}

		$actor = ActorModel::getActorByPreferredUsername($preferred_username);
		if (!$actor)
		{
			$this->sendJsonResponse(['error' => 'Unknown user'], 404);
			return;
		}

		$actor_url = ActorModel::getActorUrl($actor->preferred_username);
		$inbox_url = ActorModel::getInboxUrl($actor->preferred_username);
		$outbox_url = ActorModel::getOutboxUrl($actor->preferred_username);
		$domain = ActorModel::getSiteDomain();

		// 게시판 이름 가져오기
		$mid = ModuleModel::getMidByModuleSrl($actor->module_srl);
		$module_info = ModuleModel::getModuleInfoByModuleSrl($actor->module_srl);
		$name = '';
		if ($module_info)
		{
			$name = $module_info->browser_title ?? $mid;
		}
		if (!$name)
		{
			$name = $actor->preferred_username;
		}

		$response = [
			'@context' => [
				'https://www.w3.org/ns/activitystreams',
				'https://w3id.org/security/v1',
			],
			'id' => $actor_url,
			'type' => 'Service',
			'preferredUsername' => $actor->preferred_username,
			'name' => $name,
			'summary' => $module_info->description ?? '',
			'inbox' => $inbox_url,
			'outbox' => $outbox_url,
			'url' => ActorModel::getSiteUrl() . '?mid=' . urlencode($mid),
			'publicKey' => [
				'id' => $actor_url . '#main-key',
				'owner' => $actor_url,
				'publicKeyPem' => $actor->public_key,
			],
		];

		$this->sendJsonResponse($response, 200, 'application/activity+json');
	}

	/**
	 * Outbox 엔드포인트
	 */
	public function dispActivitypubOutbox()
	{
		$preferred_username = Context::get('preferred_username');
		if (!$preferred_username)
		{
			$this->sendJsonResponse(['error' => 'Missing username'], 400);
			return;
		}

		$actor = ActorModel::getActorByPreferredUsername($preferred_username);
		if (!$actor)
		{
			$this->sendJsonResponse(['error' => 'Unknown user'], 404);
			return;
		}

		$actor_url = ActorModel::getActorUrl($actor->preferred_username);
		$outbox_url = ActorModel::getOutboxUrl($actor->preferred_username);

		// 간단한 빈 Outbox 응답 (OrderedCollection)
		$response = [
			'@context' => 'https://www.w3.org/ns/activitystreams',
			'id' => $outbox_url,
			'type' => 'OrderedCollection',
			'totalItems' => 0,
			'orderedItems' => [],
		];

		$this->sendJsonResponse($response, 200, 'application/activity+json');
	}

	/**
	 * Inbox 엔드포인트 (POST)
	 * Follow/Undo 요청 처리
	 */
	public function procActivitypubInbox()
	{
		$preferred_username = Context::get('preferred_username');
		if (!$preferred_username)
		{
			$this->sendJsonResponse(['error' => 'Missing username'], 400);
			return;
		}

		$actor = ActorModel::getActorByPreferredUsername($preferred_username);
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

		$payload = json_decode($raw_body, true);
		if (!$payload || !isset($payload['type']))
		{
			$this->sendJsonResponse(['error' => 'Invalid JSON'], 400);
			return;
		}

		$type = $payload['type'];

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
	 * @param array $payload
	 */
	protected function handleFollow($actor, $payload)
	{
		$follower_actor_url = $payload['actor'] ?? '';
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
		$accept = [
			'@context' => 'https://www.w3.org/ns/activitystreams',
			'id' => $actor_url . '/accept/' . time(),
			'type' => 'Accept',
			'actor' => $actor_url,
			'object' => $payload,
		];

		$body = json_encode($accept, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$this->sendSignedRequest($actor, $follower_inbox_url, $body);

		$this->sendJsonResponse(['status' => 'accepted'], 202);
	}

	/**
	 * Undo 요청 처리 (Unfollow)
	 *
	 * @param object $actor
	 * @param array $payload
	 */
	protected function handleUndo($actor, $payload)
	{
		$object = $payload['object'] ?? null;
		if (!$object || !is_array($object) || ($object['type'] ?? '') !== 'Follow')
		{
			$this->sendJsonResponse(['status' => 'accepted'], 202);
			return;
		}

		$follower_actor_url = $payload['actor'] ?? '';
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
