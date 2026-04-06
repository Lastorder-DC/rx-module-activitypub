# 라이믹스 ActivityPub 연동 모듈

라이믹스 게시판을 ActivityPub 프로토콜로 연동하는 모듈입니다.

## 기능

- 게시판(mid)을 ActivityPub Actor로 등록
- 게시판의 공개 게시물/댓글을 ActivityPub으로 발행
- 포함/제외 모듈 설정 (mid 기반)
- WebFinger 지원
- ActivityPub 팔로우/언팔로우 지원
- HTTP Signature를 이용한 보안 통신

## 설치

1. 이 모듈을 라이믹스의 `modules/activitypub` 디렉토리에 복사합니다.
2. 라이믹스 관리자 페이지에서 모듈을 설치합니다.
3. 관리자 → ActivityPub 연동 설정에서 대상 게시판을 설정합니다.

## 사용 예시

게시판 mid가 `board123`이고 사이트 도메인이 `example.com`인 경우:

- ActivityPub 주소: `@board123@example.com`
- WebFinger: `https://example.com/.well-known/webfinger?resource=acct:board123@example.com`
- Actor URL: `https://example.com/?module=activitypub&act=dispActivitypubActor&preferred_username=board123`

## 주의사항

- ActivityPub 아이디(preferred_username)는 한번 설정되면 변경할 수 없습니다.
- 이 아이디는 module_srl과 영구적으로 연동됩니다.
- 게시판의 mid가 변경되더라도 ActivityPub 아이디는 최초 설정 시의 mid를 유지합니다.

## 사용 트리거

- `document.publishDocument` (after): 공개 게시물 발행 시 ActivityPub으로 전송
- `comment.insertComment` (after): 댓글 작성 시 ActivityPub으로 전송
- `moduleHandler.proc` (before): WebFinger 요청 가로채기

## 라이선스

GPLv2

## 개발자

- [Lastorder-DC](https://lastorder.xyz) (lastorder@lastorder.xyz)
