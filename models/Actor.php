<?php

namespace Rhymix\Modules\Activitypub\Models;

use ModuleModel;

/**
 * ActivityPub 연동 모듈 - Actor 모델
 *
 * Copyright (c) Lastorder-DC
 * Licensed under GPLv2
 */
class Actor
{
	/**
	 * actor_srl로 Actor 가져오기
	 *
	 * @param int $actor_srl
	 * @return object|null
	 */
	public static function getActor($actor_srl)
	{
		$args = new \stdClass;
		$args->actor_srl = $actor_srl;
		$output = executeQuery('activitypub.getActor', $args);
		if (!$output->toBool() || !$output->data)
		{
			return null;
		}
		return $output->data;
	}

	/**
	 * module_srl로 Actor 가져오기
	 *
	 * @param int $module_srl
	 * @return object|null
	 */
	public static function getActorByModuleSrl($module_srl)
	{
		$args = new \stdClass;
		$args->module_srl = $module_srl;
		$output = executeQuery('activitypub.getActorByModuleSrl', $args);
		if (!$output->toBool() || !$output->data)
		{
			return null;
		}
		return $output->data;
	}

	/**
	 * preferred_username으로 Actor 가져오기
	 *
	 * @param string $preferred_username
	 * @return object|null
	 */
	public static function getActorByPreferredUsername($preferred_username)
	{
		$args = new \stdClass;
		$args->preferred_username = $preferred_username;
		$output = executeQuery('activitypub.getActorByPreferredUsername', $args);
		if (!$output->toBool() || !$output->data)
		{
			return null;
		}
		return $output->data;
	}

	/**
	 * Actor 생성
	 * module_srl과 preferred_username(mid)을 연결하고 RSA 키 쌍 생성
	 *
	 * @param int $module_srl
	 * @param string $preferred_username
	 * @return object
	 */
	public static function createActor($module_srl, $preferred_username)
	{
		// 이미 존재하는지 확인
		$existing = self::getActorByModuleSrl($module_srl);
		if ($existing)
		{
			return new \BaseObject(-1, 'msg_activitypub_actor_already_exists');
		}

		// preferred_username 중복 확인
		$existingUsername = self::getActorByPreferredUsername($preferred_username);
		if ($existingUsername)
		{
			return new \BaseObject(-1, 'msg_activitypub_username_already_exists');
		}

		// RSA 키 쌍 생성
		$keyPair = self::generateKeyPair();
		if (!$keyPair)
		{
			return new \BaseObject(-1, 'msg_activitypub_key_generation_failed');
		}

		$args = new \stdClass;
		$args->actor_srl = getNextSequence();
		$args->module_srl = $module_srl;
		$args->preferred_username = $preferred_username;
		$args->public_key = $keyPair['public'];
		$args->private_key = $keyPair['private'];
		$args->regdate = date('YmdHis');

		$output = executeQuery('activitypub.insertActor', $args);
		if (!$output->toBool())
		{
			return $output;
		}

		$output->data = $args;
		return $output;
	}

	/**
	 * Actor 삭제
	 *
	 * @param int $actor_srl
	 * @return object
	 */
	public static function deleteActor($actor_srl)
	{
		// 팔로워 먼저 삭제
		$args = new \stdClass;
		$args->actor_srl = $actor_srl;
		executeQuery('activitypub.deleteFollowersByActorSrl', $args);

		$args = new \stdClass;
		$args->actor_srl = $actor_srl;
		return executeQuery('activitypub.deleteActor', $args);
	}

	/**
	 * Actor 목록 가져오기
	 *
	 * @param int $page
	 * @return object
	 */
	public static function getActorList($page = 1)
	{
		$args = new \stdClass;
		$args->page = $page;
		$args->list_count = 20;
		$args->page_count = 10;
		$args->sort_index = 'actor_srl';
		return executeQuery('activitypub.getActorList', $args);
	}

	/**
	 * 팔로워 추가
	 *
	 * @param int $actor_srl
	 * @param string $follower_actor_url
	 * @param string $follower_inbox_url
	 * @param string $follower_shared_inbox_url
	 * @return object
	 */
	public static function addFollower($actor_srl, $follower_actor_url, $follower_inbox_url, $follower_shared_inbox_url = '')
	{
		// 이미 팔로우 중인지 확인
		$existing = self::getFollower($actor_srl, $follower_actor_url);
		if ($existing)
		{
			$result = new \BaseObject();
			$result->data = $existing;
			return $result;
		}

		$args = new \stdClass;
		$args->follower_srl = getNextSequence();
		$args->actor_srl = $actor_srl;
		$args->follower_actor_url = $follower_actor_url;
		$args->follower_inbox_url = $follower_inbox_url;
		$args->follower_shared_inbox_url = $follower_shared_inbox_url;
		$args->regdate = date('YmdHis');

		return executeQuery('activitypub.insertFollower', $args);
	}

	/**
	 * 팔로워 삭제
	 *
	 * @param int $actor_srl
	 * @param string $follower_actor_url
	 * @return object
	 */
	public static function removeFollower($actor_srl, $follower_actor_url)
	{
		$args = new \stdClass;
		$args->actor_srl = $actor_srl;
		$args->follower_actor_url = $follower_actor_url;
		return executeQuery('activitypub.deleteFollower', $args);
	}

	/**
	 * 특정 팔로워 가져오기
	 *
	 * @param int $actor_srl
	 * @param string $follower_actor_url
	 * @return object|null
	 */
	public static function getFollower($actor_srl, $follower_actor_url)
	{
		$args = new \stdClass;
		$args->actor_srl = $actor_srl;
		$args->follower_actor_url = $follower_actor_url;
		$output = executeQuery('activitypub.getFollower', $args);
		if (!$output->toBool() || !$output->data)
		{
			return null;
		}
		return $output->data;
	}

	/**
	 * Actor의 팔로워 목록 가져오기
	 *
	 * @param int $actor_srl
	 * @param int $page
	 * @return object
	 */
	public static function getFollowers($actor_srl, $page = 1)
	{
		$args = new \stdClass;
		$args->actor_srl = $actor_srl;
		$args->page = $page;
		$args->list_count = 100;
		$args->page_count = 10;
		$args->sort_index = 'follower_srl';
		return executeQuery('activitypub.getFollowersByActorSrl', $args);
	}

	/**
	 * RSA 키 쌍 생성
	 *
	 * @return array|null ['public' => '...', 'private' => '...']
	 */
	public static function generateKeyPair()
	{
		$config = [
			'digest_alg' => 'sha256',
			'private_key_bits' => 2048,
			'private_key_type' => OPENSSL_KEYTYPE_RSA,
		];

		$resource = openssl_pkey_new($config);
		if (!$resource)
		{
			return null;
		}

		openssl_pkey_export($resource, $privateKey);
		$details = openssl_pkey_get_details($resource);

		if (!$privateKey || !$details)
		{
			return null;
		}

		return [
			'public' => $details['key'],
			'private' => $privateKey,
		];
	}

	/**
	 * 해당 module_srl이 ActivityPub 대상인지 확인
	 *
	 * @param int $module_srl
	 * @return bool
	 */
	public static function isModuleEnabled($module_srl)
	{
		$config = Config::getConfig();
		$mode = $config->target_mode ?? 'include';
		$target_mids = $config->target_mids ?? [];

		if (!is_array($target_mids) || empty($target_mids))
		{
			return false;
		}

		// mid 목록을 module_srl 목록으로 변환
		$target_module_srls = [];
		foreach ($target_mids as $mid)
		{
			$mid = trim($mid);
			if ($mid === '')
			{
				continue;
			}
			$srl_list = \ModuleModel::getModuleSrlByMid($mid);
			if (!empty($srl_list))
			{
				$target_module_srls = array_merge($target_module_srls, $srl_list);
			}
		}

		$target_module_srls = array_map('intval', $target_module_srls);

		if ($mode === 'include')
		{
			return in_array(intval($module_srl), $target_module_srls);
		}
		else
		{
			return !in_array(intval($module_srl), $target_module_srls);
		}
	}

	/**
	 * 사이트 도메인 가져오기
	 *
	 * @return string
	 */
	public static function getSiteDomain()
	{
		$site_module_info = \Context::get('site_module_info');
		if ($site_module_info && !empty($site_module_info->domain))
		{
			return $site_module_info->domain;
		}

		return $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
	}

	/**
	 * 사이트 기본 URL 가져오기
	 *
	 * @return string
	 */
	public static function getSiteUrl()
	{
		$scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
		$domain = self::getSiteDomain();
		$base_url = defined('RX_BASEURL') ? \RX_BASEURL : '/';
		return $scheme . '://' . $domain . $base_url;
	}

	/**
	 * Actor의 ActivityPub URL 가져오기
	 *
	 * @param string $preferred_username
	 * @return string
	 */
	public static function getActorUrl($preferred_username)
	{
		return self::getSiteUrl() . '?module=activitypub&act=dispActivitypubActor&preferred_username=' . urlencode($preferred_username);
	}

	/**
	 * Actor의 Inbox URL 가져오기
	 *
	 * @param string $preferred_username
	 * @return string
	 */
	public static function getInboxUrl($preferred_username)
	{
		return self::getSiteUrl() . '?module=activitypub&act=procActivitypubInbox&preferred_username=' . urlencode($preferred_username);
	}

	/**
	 * Actor의 Outbox URL 가져오기
	 *
	 * @param string $preferred_username
	 * @return string
	 */
	public static function getOutboxUrl($preferred_username)
	{
		return self::getSiteUrl() . '?module=activitypub&act=dispActivitypubOutbox&preferred_username=' . urlencode($preferred_username);
	}
}
