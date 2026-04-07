# 라이믹스 ActivityPub 연동 모듈

라이믹스 게시판 및 회원을 ActivityPub 프로토콜로 연동하는 모듈입니다.  
Mastodon, Misskey 등 Fediverse 서비스와 게시물·댓글을 주고받을 수 있습니다.

## 기능

### Actor 관리
- **게시판 Actor**: 게시판(mid)을 ActivityPub Service Actor로 등록
- **회원 Actor**: 특정 회원을 ActivityPub Person Actor로 등록
- Actor별 표시 이름, 소개, 아이콘 이미지 설정
- RSA-2048 키쌍 자동 생성 및 관리

### 게시물 발행
- 공개 게시물 작성/수정/삭제 시 ActivityPub으로 자동 전송 (Create/Update/Delete)
- 댓글 작성/수정/삭제 시 ActivityPub으로 자동 전송 (선택 설정)
- 비공개 게시판은 자동으로 발행 제외
- 카테고리 기반 포함/제외 필터링

### 콘텐츠 설정
- **본문 포함 모드**: 본문 미포함(기본) / 본문 포함 / CW(Content Warning) 모드 선택
- **댓글 표시 모드**: 일반(본문+작성자) / CW 모드(작성자가 접힌 헤더, 본문이 CW 뒤에 표시)
- **본문 길이 제한**: 최대 글자 수 설정 (기본 500자, 100~5000자 범위)
- **섬네일 이미지 첨부**: 대표 이미지를 미디어 첨부 파일로 전송 (jpeg, png, gif, webp 등)
- **민감 콘텐츠 표시**: 항상 / 특정 카테고리만 / 사용 안 함
- **작성자 표기**: 게시판 Actor일 때 게시물/댓글 본문에 작성자 닉네임 표기

### 팔로워 관리
- ActivityPub 팔로우/언팔로우 처리
- 팔로워 목록 공개/비공개 설정
- 관리자 페이지에서 팔로워 확인 및 삭제

### 보안
- **HTTP Signature** 검증으로 인증된 요청만 수신
- **Authorized Fetch (보안 모드)**: 외부 서버의 Actor 프로필 조회 시에도 서명 검증 (선택 설정)
- Mastodon 및 Misskey 방식 HTTP Signature 모두 지원

### 성능
- Rhymix Queue를 이용한 **비동기 발행** 지원 (Queue 활성화 시 자동 사용)
- 비동기 환경이 없을 경우 동기 방식으로 폴백

### ActivityPub 엔드포인트
- WebFinger (`.well-known/webfinger`)
- Actor 프로필, Inbox, Outbox, Followers, Following
- Shared Inbox
- 개별 Note 조회 엔드포인트
- 수신: Follow / Undo(언팔로우) / Delete / Update / Flag(신고) 처리

### 기타
- **공개 범위 설정**: public / unlisted / private / direct
- **인용 정책 설정**: FEP-044f `canQuote` 지원 (public / followers / following / nobody)
- **검색/색인 허용 설정**: `toot:discoverable`, `toot:indexable` 지원
- 회원 Actor의 게시판 필터 설정 (특정 게시판만 포함/제외)
- 활동 이력 저장으로 삭제 동기화 지원

## 설치

1. 이 모듈을 라이믹스의 `modules/activitypub` 디렉토리에 복사합니다.
2. `composer install`로 의존성을 설치합니다.
3. 라이믹스 관리자 페이지에서 모듈을 설치합니다.
4. 관리자 → ActivityPub 연동 설정에서 Actor를 생성하고 설정합니다.

## 사용 예시

생성한 Actor의 `preferred_username`이 `board123`이고 사이트 도메인이 `example.com`인 경우:

- ActivityPub 주소: `@board123@example.com`
- WebFinger: `https://example.com/.well-known/webfinger?resource=acct:board123@example.com`
- Actor URL: `https://example.com/?module=activitypub&act=dispActivitypubActor&preferred_username=board123`

## 전역 설정 항목

| 설정 | 기본값 | 설명 |
|------|--------|------|
| 모듈 활성화 | Y | 전체 모듈 활성화/비활성화 |
| 디버그 모드 | N | `/files/debug/activitypub.php`에 로그 기록 |
| 댓글 전송 | N | 댓글 ActivityPub 발행 여부 |
| Authorized Fetch | N | 외부 요청 서명 검증 강제 여부 |
| 본문 최대 길이 | 500 | 발행 본문 최대 글자 수 (100~5000) |
| 본문 포함 모드 | N | N(미포함) / Y(포함) / cw(CW 모드) |
| 댓글 표시 모드 | plain | plain(일반) / cw(CW 모드) |
| Outbox 페이지 크기 | 20 | Outbox 페이지당 항목 수 (5~100) |

## Actor별 설정 항목

| 설정 | 설명 |
|------|------|
| 표시 이름 | Actor 이름 (미설정 시 게시판명/회원명 사용) |
| 소개 | Actor 프로필 설명 |
| 아이콘 URL | 프로필 이미지 URL |
| 팔로워 숨기기 | 팔로워 목록 비공개 여부 |
| 검색 허용 | `toot:discoverable` 설정 |
| 색인 허용 | `toot:indexable` 설정 |
| 공개 범위 | public / unlisted / private / direct |
| 인용 정책 | public / followers / following / nobody |
| 카테고리 필터 | 특정 카테고리 포함/제외 |
| 섬네일 첨부 | 대표 이미지 첨부 여부 |
| 민감 콘텐츠 | 항상 / 카테고리별 / 사용 안 함 |
| 게시판 필터 (회원 Actor) | 발행할 게시판 포함/제외 |

## 주의사항

- ActivityPub 아이디(`preferred_username`)는 한번 설정되면 변경할 수 없습니다.
- 이 아이디는 `module_srl` 또는 `member_srl`과 영구적으로 연동됩니다.
- 게시판의 mid가 변경되더라도 ActivityPub 아이디는 최초 설정 시의 값을 유지합니다.

## 사용 트리거

### 게시물
- `document.publishDocument` (after): 게시물 발행 시 Create 전송
- `document.updateDocument` (after): 게시물 수정 시 Update/Delete 전송
- `document.deleteDocument` (before): 게시물 삭제 시 Delete 전송
- `document.moveDocumentToTrash` (before): 게시물 휴지통 이동 시 Delete 전송

### 댓글
- `comment.insertComment` (after): 댓글 작성 시 Create 전송
- `comment.updateComment` (after): 댓글 수정 시 Update/Delete 전송
- `comment.deleteComment` (before): 댓글 삭제 시 Delete 전송
- `comment.moveCommentToTrash` (before): 댓글 휴지통 이동 시 Delete 전송

### 시스템
- `moduleHandler.proc` (before): WebFinger 요청 가로채기

## 라이선스

GPLv2

## 개발자

- [Lastorder-DC](https://lastorder.xyz) (lastorder@lastorder.xyz)
