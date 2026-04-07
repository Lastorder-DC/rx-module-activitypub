<?php

namespace Rhymix\Modules\Activitypub\Controllers;

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * ActivityPub 연동 모듈 - 기본 컨트롤러
 *
 * Copyright (c) Lastorder-DC
 * Licensed under GPLv2
 */
class Base extends \ModuleObject
{
	/**
	 * 디버그 메시지를 파일에 기록
	 *
	 * exit; 호출로 인해 라이믹스의 debugPrint 핸들러가 동작하지 않으므로,
	 * 수동으로 파일에 메시지를 저장합니다.
	 *
	 * @param string $message
	 */
	public static function debugLog($message)
	{
		// 디버그 모드가 비활성화된 경우 즉시 반환
		$config = \Rhymix\Modules\Activitypub\Models\Config::getConfig();
		if (($config->debug_enabled ?? 'N') !== 'Y')
		{
			return;
		}

		$dir = \RX_BASEDIR . 'files/debug';
		if (!is_dir($dir))
		{
			@mkdir($dir, 0755, true);
		}

		$file = $dir . '/activitypub.php';

		// 파일이 없으면 PHP exit 가드를 추가하여 웹에서 직접 접근 방지
		if (!file_exists($file))
		{
			@file_put_contents($file, "<?php exit; ?>\n", LOCK_EX);
		}

		$timestamp = date('Y-m-d H:i:s');
		$entry = '[' . $timestamp . '] ' . $message . "\n";
		@file_put_contents($file, $entry, FILE_APPEND | LOCK_EX);
	}

	/**
	 * HTML 컨텐츠에서 태그를 제거하고 설정된 최대 길이로 잘라내기
	 *
	 * @param string $html
	 * @return string
	 */
	public static function truncateContent($html)
	{
		$maxLength = \Rhymix\Modules\Activitypub\Models\Config::getContentMaxLength();
		$text = strip_tags($html);
		$text = ltrim($text, "\n\r");
		if (mb_strlen($text) > $maxLength)
		{
			$text = mb_substr($text, 0, $maxLength - 3) . '...';
		}
		return $text;
	}

	/**
	 * '작성자' 라벨 텍스트 반환 (다국어 지원)
	 *
	 * @return string
	 */
	public static function getAuthorLabel()
	{
		if (function_exists('lang'))
		{
			$label = lang('cmd_activitypub_author_prefix');
			if ($label && $label !== 'cmd_activitypub_author_prefix')
			{
				return $label;
			}
		}
		return '작성자';
	}

	/**
	 * 게시물 Note의 HTML 컨텐츠와 summary를 생성
	 *
	 * @param string $title 게시물 제목
	 * @param string $content_text strip_tags 및 truncate 처리된 본문 텍스트
	 * @param string $nick_name 작성자 닉네임
	 * @param string $document_url 게시물 URL
	 * @param object $actor Actor 객체
	 * @return array ['content' => string, 'summary' => string|null]
	 */
	public static function buildDocumentNoteContent($title, $content_text, $nick_name, $document_url, $actor)
	{
		$include_content = \Rhymix\Modules\Activitypub\Models\Config::getIncludeContent();
		$escaped_title = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
		$escaped_url = htmlspecialchars($document_url, ENT_QUOTES, 'UTF-8');
		$show_author = $nick_name && ($actor->actor_type ?? 'board') === 'board';
		$escaped_nick = $show_author ? htmlspecialchars($nick_name, ENT_QUOTES, 'UTF-8') : '';

		$result = ['content' => '', 'summary' => null];

		if ($include_content === 'cw')
		{
			// CW 모드: summary에 제목+작성자, content에 본문+링크
			$summary = $title;
			if ($show_author)
			{
				$summary .= ' - ' . self::getAuthorLabel() . ': ' . $nick_name;
			}
			$result['summary'] = $summary;

			$html_content = '';
			if ($content_text !== '')
			{
				$html_content .= '<p>' . htmlspecialchars($content_text, ENT_QUOTES, 'UTF-8') . '</p>';
			}
			$html_content .= '<p><a href="' . $escaped_url . '">' . $escaped_url . '</a></p>';
			$result['content'] = $html_content;
		}
		else
		{
			// 일반 모드 (N 또는 Y): content에 제목+작성자(+본문)+링크
			$html_content = '<p><strong>' . $escaped_title . '</strong>';
			if ($show_author)
			{
				$html_content .= '<br />' . self::getAuthorLabel() . ': ' . $escaped_nick;
			}
			$html_content .= '</p>';
			if ($include_content === 'Y' && $content_text !== '')
			{
				$html_content .= '<p>' . htmlspecialchars($content_text, ENT_QUOTES, 'UTF-8') . '</p>';
			}
			$html_content .= '<p><a href="' . $escaped_url . '">' . $escaped_url . '</a></p>';
			$result['content'] = $html_content;
		}

		return $result;
	}

	/**
	 * 댓글 Note의 HTML 컨텐츠와 summary를 생성
	 *
	 * @param string $content_text strip_tags 및 truncate 처리된 댓글 본문 텍스트
	 * @param string $nick_name 작성자 닉네임
	 * @param string $comment_url 댓글 URL
	 * @param object $actor Actor 객체
	 * @return array ['content' => string, 'summary' => string|null]
	 */
	public static function buildCommentNoteContent($content_text, $nick_name, $comment_url, $actor)
	{
		$comment_content_mode = \Rhymix\Modules\Activitypub\Models\Config::getCommentContentMode();
		$escaped_url = htmlspecialchars($comment_url, ENT_QUOTES, 'UTF-8');
		$show_author = $nick_name && ($actor->actor_type ?? 'board') === 'board';

		$result = ['content' => '', 'summary' => null];

		if ($comment_content_mode === 'cw')
		{
			// CW 모드: summary에 작성자, content에 댓글 본문+링크
			if ($show_author)
			{
				$result['summary'] = self::getAuthorLabel() . ': ' . $nick_name;
			}

			$html_content = '';
			if ($content_text !== '')
			{
				$html_content .= '<p>' . htmlspecialchars($content_text, ENT_QUOTES, 'UTF-8') . '</p>';
			}
			$html_content .= '<p><a href="' . $escaped_url . '">' . $escaped_url . '</a></p>';
			$result['content'] = $html_content;
		}
		else
		{
			// 일반 모드: content에 댓글 본문+작성자+링크
			$html_content = '';
			if ($content_text !== '')
			{
				$html_content .= '<p>' . htmlspecialchars($content_text, ENT_QUOTES, 'UTF-8');
				if ($show_author)
				{
					$html_content .= '<br />' . self::getAuthorLabel() . ': ' . htmlspecialchars($nick_name, ENT_QUOTES, 'UTF-8');
				}
				$html_content .= '</p>';
			}
			elseif ($show_author)
			{
				$html_content .= '<p>' . self::getAuthorLabel() . ': ' . htmlspecialchars($nick_name, ENT_QUOTES, 'UTF-8') . '</p>';
			}
			$html_content .= '<p><a href="' . $escaped_url . '">' . $escaped_url . '</a></p>';
			$result['content'] = $html_content;
		}

		return $result;
	}

	/**
	 * 허용되는 이미지 MIME 타입 목록
	 */
	protected static $allowedImageMimeTypes = [
		'image/jpeg',
		'image/png',
		'image/gif',
		'image/webp',
		'image/svg+xml',
		'image/bmp',
		'image/tiff',
	];

	/**
	 * 안전한 이미지 MIME 타입인지 확인
	 *
	 * @param string $mimeType
	 * @return bool
	 */
	public static function isAllowedImageMimeType($mimeType)
	{
		return in_array($mimeType, self::$allowedImageMimeTypes, true);
	}
}
