<?php

namespace Rhymix\Modules\Activitypub\Controllers;

use Rhymix\Modules\Activitypub\Models\Actor as ActorModel;
use Rhymix\Modules\Activitypub\Models\Config as ConfigModel;
use ActivityPhp\Server;
use ActivityPhp\Server\Http\HttpSignature;
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
			$note_id = $actor_url . '/note/' . $document_srl;

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
				'id' => $note_id . '/activity',
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
			// Rhymix의 Context가 POST 요청에서 쿼리 스트링 파라미터를 가져오지 못하는 경우를 대비한 폴백
			$preferred_username = $_GET['preferred_username'] ?? '';
		}
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
	 * HTTP Signature 검증 (landrok/activitypub 라이브러리 사용)
	 *
	 * @return bool
	 */
	protected function verifyHttpSignature()
	{
		try
		{
			$server = $this->createActivityPubServer();
			$httpSignature = new HttpSignature($server);

			// Symfony Request에서 쿼리 스트링 정렬을 방지하기 위해
			// ActivityPubRequest 서브클래스를 사용
			$request = ActivityPubRequest::createFromGlobals();

			return $httpSignature->verify($request);
		}
		catch (\Exception $e)
		{
			return false;
		}
	}

	/**
	 * ActivityPhp Server 인스턴스 생성
	 *
	 * @return \ActivityPhp\Server
	 */
	protected function createActivityPubServer()
	{
		$domain = ActorModel::getSiteDomain();
		$scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
		$port = ($scheme === 'https') ? 443 : 80;

		return new Server([
			'instance' => [
				'host' => $domain,
				'port' => $port,
				'debug' => false,
			],
			'logger' => [
				'driver' => '\Psr\Log\NullLogger',
			],
			'cache' => [
				'enabled' => false,
			],
		]);
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
