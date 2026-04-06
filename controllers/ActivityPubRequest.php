<?php

namespace Rhymix\Modules\Activitypub\Controllers;

use Symfony\Component\HttpFoundation\Request;

/**
 * Symfony Request 서브클래스 - HTTP Signature 검증용
 *
 * Symfony의 getQueryString()은 쿼리 파라미터를 알파벳순으로 정렬하므로
 * HTTP Signature 검증 시 서명 문자열이 일치하지 않게 됩니다.
 * 이 클래스는 원본 쿼리 스트링 순서를 유지하여 서명 검증이 정상 동작하도록 합니다.
 *
 * Copyright (c) Lastorder-DC
 * Licensed under GPLv2
 */
class ActivityPubRequest extends Request
{
	/**
	 * 원본 쿼리 스트링을 정렬 없이 반환
	 * (Symfony의 기본 동작은 ksort로 파라미터를 정렬함)
	 *
	 * @return string|null
	 */
	public function getQueryString(): ?string
	{
		$qs = $this->server->get('QUERY_STRING');
		return ($qs === '' || $qs === null) ? null : $qs;
	}
}
