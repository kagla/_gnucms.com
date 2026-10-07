# GNUCMS 릴리스

릴리스는 PR 없이 GitHub Actions의 `Release` 워크플로를 수동 실행해 만듭니다.
일반 `main` push는 릴리스를 만들지 않습니다. 릴리스 실행과 운영 사이트에 배포본을 적용하는 작업은 별개입니다.

## 이름과 버전

| 항목 | 형식 | 예 |
| --- | --- | --- |
| 제품 버전 (`version.txt`) | `X.Y.Z` | `0.8.0` |
| Git 태그 | `vX.Y.Z` | `v0.8.0` |
| GitHub Release 제목 | `GNUCMS vX.Y.Z` | `GNUCMS v0.8.0` |
| 배포 ZIP | `gnucms-X.Y.Z.zip` | `gnucms-0.8.0.zip` |
| 체크섬 | `gnucms-X.Y.Z.zip.sha256` | `gnucms-0.8.0.zip.sha256` |

SemVer를 따릅니다. 버그 수정만 있으면 PATCH를 1 올리고 MAJOR·MINOR는 유지합니다.
하위 호환 기능 추가는 MINOR, 호환성을 깨는 변경은 MAJOR를 올립니다.
정식 릴리스에는 사전 릴리스 접미사나 `v`가 들어간 버전을 입력하지 않습니다.
제품 버전과 `Schema::VERSION`은 별개입니다.

## 릴리스 준비와 실행

1. 배포할 소스와 문서를 `main`에 반영합니다. 기능 작업 중에는 `version.txt`를 직접 올리지 않습니다.
2. `CHANGELOG.md` 맨 위에 `## [Unreleased]`를 작성합니다. 사용자 관점의 한국어 변경사항과
   `### 업그레이드 안내`를 넣습니다. 업그레이드 주의사항이 없으면 그렇게 명시합니다.
   커밋 제목 목록이나 민감한 운영 정보를 본문으로 넣지 않습니다.
3. GitHub 저장소에서 **Actions → Release → Run workflow**를 엽니다.
4. 브랜치는 **main**, 버전은 `v` 없는 `X.Y.Z`로 입력하고 실행합니다.
5. 완료되면 **Releases**에서 제목이 `GNUCMS vX.Y.Z`이고 ZIP과 체크섬이 첨부되었는지 확인합니다.

CLI를 사용할 때도 같은 수동 워크플로를 실행합니다.

```sh
gh workflow run release.yml --repo kagla/gnucms --ref main -f version=0.8.0
```

워크플로는 실행 시 checkout한 `main` 소스를 사용합니다. `version.txt`를 갱신하고
`[Unreleased]` 제목을 버전·비교 링크·한국 시간대의 릴리스 날짜로 바꿉니다.
변경 내역 본문을 GitHub Release 설명으로 그대로 사용하고 `chore: release X.Y.Z` 커밋을 만듭니다.

CI에서 운영용 Composer 의존성과 정적 자산, Linux·Windows KCP 모듈 및 `pub.key`를 묶습니다.
ZIP 빌드가 성공한 뒤 버전 커밋과 태그를 한 번에 push합니다. 빌드 도중 `main`이 바뀌어
push가 거절되면 태그도 올리지 않습니다. 새 `main`의 변경 내역과 버전을 확인해 다시 실행합니다.
완성 ZIP과 SHA-256 체크섬을 릴리스 초안에 첨부한 뒤 공개하고 최신 릴리스로 표시합니다.
저장소의 브랜치 규칙에서 Actions의 `main` 직접 push를 막으면 이 단계가 실패합니다.

자동 테스트나 PHP 문법 검사는 이 워크플로에서 실행하지 않습니다. 별도 사용자 요청이 있을 때만
전용 개발·테스트 환경에서 수행합니다. ZIP 무결성과 배포 구성요소 확인은 빌드 단계에 포함됩니다.

## 실패 후 재실행

* 빌드가 실패하면 원격 버전·태그·릴리스를 생성하지 않습니다. 원인을 수정하고 같은 버전으로 실행합니다.
* 태그 push 뒤 초안 생성·첨부·공개가 실패하면 같은 버전으로 실행합니다. 기존 태그가 `main`의
  이력에 속하고 태그의 `version.txt`가 일치하는지 확인한 뒤 그 소스로 다시 빌드합니다.
  이미 있는 초안에는 ZIP과 체크섬을 다시 첨부하고 공개합니다.
* 이후 버전을 배포한 뒤에는 이전 버전으로 재실행할 수 없습니다.
* 이미 공개한 릴리스는 재실행으로 덮어쓰지 않습니다. 수정이 필요하면 새 버전을 준비합니다.
* 과거 릴리스 제목만 정리할 때는 `GNUCMS vX.Y.Z`로 바꾸고 태그·본문·첨부 파일을 유지합니다.

## 배포본 사용

GitHub가 기본 제공하는 `Source code (zip)`에는 실행에 필요한 `vendor/`가 없습니다.
운영 사이트에는 릴리스 Assets의 `gnucms-X.Y.Z.zip`을 사용합니다.
운영 서버에서 Composer·Node.js·npm 설치나 빌드를 하지 않습니다.
교체 전에 전체 백업을 보관하고 `config/config.php`, `storage/`, 사용자 테마·확장을 보존합니다.
자세한 적용 순서는 공개 매뉴얼의 업그레이드 문서를 따릅니다.

Release Please 설정과 버전 manifest는 사용하지 않습니다. 전환 시 이전 릴리스 PR은
새 워크플로가 `main`에 반영된 뒤 병합하지 않고 닫습니다.
