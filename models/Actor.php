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
	 * module_srl로 게시판 타입 Actor 가져오기
	 *
	 * @param int $module_srl
	 * @return object|null
	 */
	public static function getBoardActorByModuleSrl($module_srl)
	{
		$args = new \stdClass;
		$args->actor_type = 'board';
		$args->module_srl = $module_srl;
		$output = executeQuery('activitypub.getBoardActorByModuleSrl', $args);
		if (!$output->toBool() || !$output->data)
		{
			return null;
		}
		return $output->data;
	}

	/**
	 * module_srl로 Actor 가져오기 (하위호환)
	 *
	 * @param int $module_srl
	 * @return object|null
	 */
	public static function getActorByModuleSrl($module_srl)
	{
		return self::getBoardActorByModuleSrl($module_srl);
	}

	/**
	 * member_srl로 유저 타입 Actor 가져오기
	 *
	 * @param int $member_srl
	 * @return object|null
	 */
	public static function getUserActorByMemberSrl($member_srl)
	{
		$args = new \stdClass;
		$args->actor_type = 'user';
		$args->member_srl = $member_srl;
		$output = executeQuery('activitypub.getActorByMemberSrl', $args);
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
	 * preferred_username으로 활성 Actor 가져오기 (삭제되지 않은 Actor만)
	 *
	 * @param string $preferred_username
	 * @return object|null
	 */
	public static function getActiveActorByPreferredUsername($preferred_username)
	{
		$actor = self::getActorByPreferredUsername($preferred_username);
		if ($actor && ($actor->is_deleted ?? 'N') === 'Y')
		{
			return null;
		}
		return $actor;
	}

	/**
	 * 특정 게시물/댓글에 해당하는 모든 Actor를 가져오기
	 * 게시판 Actor + 유저 Actor(필터 통과 시)를 반환
	 *
	 * @param int $module_srl 게시판 module_srl
	 * @param int $member_srl 작성자 member_srl
	 * @return array Actor 목록
	 */
	public static function getActorsForDocument($module_srl, $member_srl = 0)
	{
		$actors = [];

		// 1. 게시판 타입 Actor 확인
		$board_actor = self::getBoardActorByModuleSrl($module_srl);
		if ($board_actor)
		{
			$actors[] = $board_actor;
		}

		// 2. 유저 타입 Actor 확인 (member_srl이 있는 경우)
		if ($member_srl)
		{
			$user_actor = self::getUserActorByMemberSrl($member_srl);
			if ($user_actor)
			{
				// 모듈 필터 확인
				$filter_modules = self::getActorModules($user_actor->actor_srl);
				if (empty($filter_modules))
				{
					// 필터가 비어있으면 모든 게시판 대상
					$actors[] = $user_actor;
				}
				else
				{
					// 필터에 해당 모듈이 포함되어 있는지 확인
					$filter_module_srls = array_map('intval', array_column($filter_modules, 'module_srl'));
					if (in_array(intval($module_srl), $filter_module_srls))
					{
						$actors[] = $user_actor;
					}
				}
			}
		}

		return $actors;
	}

	/**
	 * 게시판 타입 Actor 생성
	 *
	 * @param int $module_srl
	 * @param string $preferred_username
	 * @param string $display_name
	 * @param string $summary
	 * @param string $icon_url
	 * @return object
	 */
	public static function createBoardActor($module_srl, $preferred_username, $display_name = '', $summary = '', $icon_url = '', $hide_followers = 'N')
	{
		// 이미 존재하는지 확인
		$existing = self::getBoardActorByModuleSrl($module_srl);
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
		$args->actor_type = 'board';
		$args->module_srl = $module_srl;
		$args->preferred_username = $preferred_username;
		$args->display_name = $display_name;
		$args->summary = $summary;
		$args->icon_url = $icon_url;
		$args->hide_followers = $hide_followers;
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
	 * 유저 타입 Actor 생성
	 *
	 * @param int $member_srl
	 * @param string $preferred_username
	 * @param string $display_name
	 * @param string $summary
	 * @param string $icon_url
	 * @return object
	 */
	public static function createUserActor($member_srl, $preferred_username, $display_name = '', $summary = '', $icon_url = '', $hide_followers = 'N')
	{
		// 이미 이 유저에 대한 Actor가 존재하는지 확인
		$existing = self::getUserActorByMemberSrl($member_srl);
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
		$args->actor_type = 'user';
		$args->member_srl = $member_srl;
		$args->preferred_username = $preferred_username;
		$args->display_name = $display_name;
		$args->summary = $summary;
		$args->icon_url = $icon_url;
		$args->hide_followers = $hide_followers;
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
	 * Actor 생성 (하위호환)
	 *
	 * @param int $module_srl
	 * @param string $preferred_username
	 * @return object
	 */
	public static function createActor($module_srl, $preferred_username)
	{
		return self::createBoardActor($module_srl, $preferred_username);
	}

	/**
	 * Actor 프로필 업데이트 (표시 이름, 설명, 아이콘 URL)
	 *
	 * @param int $actor_srl
	 * @param string $display_name
	 * @param string $summary
	 * @param string $icon_url
	 * @return object
	 */
	public static function updateActorProfile($actor_srl, $display_name, $summary, $icon_url, $hide_followers = 'N')
	{
		$args = new \stdClass;
		$args->actor_srl = $actor_srl;
		$args->display_name = $display_name;
		$args->summary = $summary;
		$args->icon_url = $icon_url;
		$args->hide_followers = $hide_followers;
		return executeQuery('activitypub.updateActorProfile', $args);
	}

	/**
	 * Actor 삭제 (소프트 삭제 - preferred_username 보존)
	 *
	 * @param int $actor_srl
	 * @return object
	 */
	public static function deleteActor($actor_srl)
	{
		// 모듈 필터 삭제
		$args = new \stdClass;
		$args->actor_srl = $actor_srl;
		executeQuery('activitypub.deleteActorModulesByActorSrl', $args);

		// 팔로워 삭제
		$args = new \stdClass;
		$args->actor_srl = $actor_srl;
		executeQuery('activitypub.deleteFollowersByActorSrl', $args);

		// Actor 소프트 삭제 (preferred_username과 regdate만 보존)
		$args = new \stdClass;
		$args->actor_srl = $actor_srl;
		$args->module_srl = 0;
		$args->member_srl = 0;
		$args->display_name = '';
		$args->summary = '';
		$args->icon_url = '';
		$args->public_key = '';
		$args->private_key = '';
		$args->hide_followers = 'N';
		$args->is_deleted = 'Y';
		return executeQuery('activitypub.softDeleteActor', $args);
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
	 * 유저 Actor의 모듈 필터 목록 가져오기
	 *
	 * @param int $actor_srl
	 * @return array
	 */
	public static function getActorModules($actor_srl)
	{
		$args = new \stdClass;
		$args->actor_srl = $actor_srl;
		$output = executeQuery('activitypub.getActorModulesByActorSrl', $args);
		if (!$output->toBool() || !$output->data)
		{
			return [];
		}
		return is_array($output->data) ? $output->data : [$output->data];
	}

	/**
	 * 유저 Actor의 모듈 필터 설정
	 *
	 * @param int $actor_srl
	 * @param array $module_srls
	 * @return object
	 */
	public static function setActorModules($actor_srl, $module_srls)
	{
		// 기존 필터 삭제
		$args = new \stdClass;
		$args->actor_srl = $actor_srl;
		executeQuery('activitypub.deleteActorModulesByActorSrl', $args);

		// 새 필터 추가
		foreach ($module_srls as $module_srl)
		{
			$module_srl = intval($module_srl);
			if ($module_srl <= 0)
			{
				continue;
			}
			$args = new \stdClass;
			$args->actor_module_srl = getNextSequence();
			$args->actor_srl = $actor_srl;
			$args->module_srl = $module_srl;
			$output = executeQuery('activitypub.insertActorModule', $args);
			if (!$output->toBool())
			{
				return $output;
			}
		}

		return new \BaseObject();
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
	 * follower_srl로 팔로워 삭제
	 *
	 * @param int $follower_srl
	 * @return object
	 */
	public static function removeFollowerByFollowerSrl($follower_srl)
	{
		$args = new \stdClass;
		$args->follower_srl = $follower_srl;
		return executeQuery('activitypub.deleteFollowerByFollowerSrl', $args);
	}

	/**
	 * follower_srl로 팔로워 정보 가져오기
	 *
	 * @param int $follower_srl
	 * @return object|null
	 */
	public static function getFollowerByFollowerSrl($follower_srl)
	{
		$args = new \stdClass;
		$args->follower_srl = $follower_srl;
		$output = executeQuery('activitypub.getFollowerByFollowerSrl', $args);
		if (!$output->toBool() || !$output->data)
		{
			return null;
		}
		return $output->data;
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
	 * Actor에 해당하는 공개 게시물 목록 가져오기
	 * 게시판 Actor: module_srl에 해당하는 게시물
	 * 유저 Actor: member_srl에 해당하는 게시물 (모듈 필터 적용)
	 *
	 * @param object $actor
	 * @param int $page
	 * @param int $list_count
	 * @return object
	 */
	public static function getDocumentsForActor($actor, $page = 1, $list_count = 20)
	{
		$args = new \stdClass;
		$args->statusList = ['PUBLIC'];
		$args->page = $page;
		$args->list_count = $list_count;
		$args->page_count = 10;
		$args->sort_index = 'regdate';
		$args->order_type = 'desc';

		$actor_type = $actor->actor_type ?? 'board';

		if ($actor_type === 'board' && $actor->module_srl)
		{
			// 게시판이 공개 접근 가능한지 확인
			if (!self::isModulePubliclyAccessible($actor->module_srl))
			{
				$result = new \BaseObject();
				$result->data = [];
				$result->total_count = 0;
				return $result;
			}
			$args->module_srl = $actor->module_srl;
		}
		elseif ($actor_type === 'user' && $actor->member_srl)
		{
			$args->member_srl = $actor->member_srl;

			// 모듈 필터가 설정된 경우 해당 모듈만
			$filter_modules = self::getActorModules($actor->actor_srl);
			if (!empty($filter_modules))
			{
				$module_srls = [];
				foreach ($filter_modules as $fm)
				{
					$module_srl = intval($fm->module_srl ?? $fm['module_srl'] ?? 0);
					if ($module_srl > 0 && self::isModulePubliclyAccessible($module_srl))
					{
						$module_srls[] = $module_srl;
					}
				}
				if (empty($module_srls))
				{
					$result = new \BaseObject();
					$result->data = [];
					$result->total_count = 0;
					return $result;
				}
				$args->module_srl = $module_srls;
			}
		}
		else
		{
			$result = new \BaseObject();
			$result->data = [];
			$result->total_count = 0;
			return $result;
		}

		return \DocumentModel::getDocumentList($args);
	}

	/**
	 * AP로 발송된 활동 기록 추가
	 *
	 * @param int $actor_srl
	 * @param string $object_type 'document' or 'comment'
	 * @param int $object_srl
	 * @param int $module_srl
	 * @param int $member_srl
	 * @return object
	 */
	public static function addActivity($actor_srl, $object_type, $object_srl, $module_srl = 0, $member_srl = 0)
	{
		// 이미 존재하는지 확인
		$existing = self::getActivityByActorAndObject($actor_srl, $object_type, $object_srl);
		if ($existing)
		{
			return new \BaseObject();
		}

		$args = new \stdClass;
		$args->activity_srl = getNextSequence();
		$args->actor_srl = $actor_srl;
		$args->object_type = $object_type;
		$args->object_srl = $object_srl;
		$args->module_srl = $module_srl;
		$args->member_srl = $member_srl;
		$args->regdate = date('YmdHis');
		return executeQuery('activitypub.insertActivity', $args);
	}

	/**
	 * 특정 오브젝트에 대한 활동 기록 가져오기
	 *
	 * @param string $object_type 'document' or 'comment'
	 * @param int $object_srl
	 * @return array
	 */
	public static function getActivitiesByObjectSrl($object_type, $object_srl)
	{
		$args = new \stdClass;
		$args->object_type = $object_type;
		$args->object_srl = $object_srl;
		$output = executeQuery('activitypub.getActivitiesByObjectSrl', $args);
		if (!$output->toBool() || !$output->data)
		{
			return [];
		}
		return is_array($output->data) ? $output->data : [$output->data];
	}

	/**
	 * 특정 Actor + 오브젝트에 대한 활동 기록 가져오기
	 *
	 * @param int $actor_srl
	 * @param string $object_type
	 * @param int $object_srl
	 * @return object|null
	 */
	public static function getActivityByActorAndObject($actor_srl, $object_type, $object_srl)
	{
		$args = new \stdClass;
		$args->actor_srl = $actor_srl;
		$args->object_type = $object_type;
		$args->object_srl = $object_srl;
		$output = executeQuery('activitypub.getActivityByActorAndObject', $args);
		if (!$output->toBool() || !$output->data)
		{
			return null;
		}
		return $output->data;
	}

	/**
	 * 특정 오브젝트에 대한 활동 기록 삭제
	 *
	 * @param string $object_type 'document' or 'comment'
	 * @param int $object_srl
	 * @return object
	 */
	public static function deleteActivitiesByObjectSrl($object_type, $object_srl)
	{
		$args = new \stdClass;
		$args->object_type = $object_type;
		$args->object_srl = $object_srl;
		return executeQuery('activitypub.deleteActivitiesByObjectSrl', $args);
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
	 * CLI/Queue 환경에서도 올바른 scheme을 반환하도록 site_module_info->security 확인
	 *
	 * 주의: Context::getRequestUri()는 CLI 환경에서 RX_BASEURL(파일시스템 절대경로)이
	 * URL에 붙는 버그가 있으므로 사용하지 않음
	 *
	 * @return string
	 */
	public static function getSiteUrl()
	{
		$site_module_info = \Context::get('site_module_info');

		// site_module_info에서 security 설정을 확인하여 scheme 결정 (CLI/Queue 환경 대응)
		if ($site_module_info && !empty($site_module_info->domain))
		{
			$scheme = (!empty($site_module_info->security) && $site_module_info->security !== 'none') ? 'https' : 'http';
			return $scheme . '://' . $site_module_info->domain . '/';
		}

		// Fallback: $_SERVER에서 추출
		$scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
		$domain = self::getSiteDomain();
		return $scheme . '://' . $domain . '/';
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

	/**
	 * Actor의 Followers URL 가져오기
	 *
	 * @param string $preferred_username
	 * @return string
	 */
	public static function getFollowersUrl($preferred_username)
	{
		return self::getSiteUrl() . '?module=activitypub&act=dispActivitypubFollowers&preferred_username=' . urlencode($preferred_username);
	}

	/**
	 * Actor의 Following URL 가져오기
	 *
	 * @param string $preferred_username
	 * @return string
	 */
	public static function getFollowingUrl($preferred_username)
	{
		return self::getSiteUrl() . '?module=activitypub&act=dispActivitypubFollowing&preferred_username=' . urlencode($preferred_username);
	}

	/**
	 * Note(게시물)의 ActivityPub URL 가져오기
	 *
	 * @param string $preferred_username
	 * @param int $document_srl
	 * @return string
	 */
	public static function getNoteUrl($preferred_username, $document_srl)
	{
		return self::getSiteUrl() . '?module=activitypub&act=dispActivitypubNote&preferred_username=' . urlencode($preferred_username) . '&document_srl=' . intval($document_srl);
	}

	/**
	 * Note(댓글)의 ActivityPub URL 가져오기
	 *
	 * @param string $preferred_username
	 * @param int $comment_srl
	 * @return string
	 */
	public static function getCommentNoteUrl($preferred_username, $comment_srl)
	{
		return self::getSiteUrl() . '?module=activitypub&act=dispActivitypubNote&preferred_username=' . urlencode($preferred_username) . '&comment_srl=' . intval($comment_srl);
	}

	/**
	 * 공유 Inbox URL 가져오기
	 *
	 * @return string
	 */
	public static function getSharedInboxUrl()
	{
		return self::getSiteUrl() . '?module=activitypub&act=procActivitypubSharedInbox';
	}

	/**
	 * 모듈(게시판)이 비로그인 사용자에게 공개되어 있는지 확인
	 *
	 * 게시판 접속/읽기 권한이 로그인된 사용자만 가능하거나
	 * 상담 게시판인 경우 false를 반환합니다.
	 *
	 * @param int $module_srl
	 * @return bool
	 */
	public static function isModulePubliclyAccessible($module_srl)
	{
		$module_info = ModuleModel::getModuleInfoByModuleSrl($module_srl);
		if (!$module_info)
		{
			return false;
		}

		// 상담 게시판 제외
		if (!empty($module_info->consultation) && $module_info->consultation === 'Y')
		{
			return false;
		}

		// 비로그인 사용자(게스트)에 대한 접근 권한 확인
		$oModuleModel = getModel('module');
		$guest = new \stdClass();
		$guest->member_srl = 0;
		$guest->is_admin = 'N';
		$guest->group_list = [];
		$grant = $oModuleModel->getGrant($module_info, $guest);

		// 접속 권한이 없으면 비공개
		if (empty($grant->access))
		{
			return false;
		}

		// 읽기(view) 권한이 없으면 비공개
		if (empty($grant->view))
		{
			return false;
		}

		return true;
	}
}
