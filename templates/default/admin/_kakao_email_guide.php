<details class="oauth-setup-guide" open>
  <summary>카카오 이메일 제공 동의 설정 방법</summary>
  <div class="oauth-setup-guide-body">
    <ol>
      <li><a class="link" href="<?= $this->e($console_url) ?>" target="_blank" rel="noopener noreferrer">카카오디벨로퍼스 애플리케이션 관리 <span aria-hidden="true">↗</span></a>에서 이 사이트와 연결할 앱을 선택합니다.</li>
      <li><strong>카카오 로그인 → 사용 설정</strong>에서 상태를 <strong>ON</strong>으로 바꿉니다.</li>
      <li><strong>카카오 로그인 → 리다이렉트 URI</strong>에 <code><?= $this->e($callback_url) ?></code>를 정확히 등록합니다.</li>
      <li><strong>카카오 로그인 → 동의항목 → 개인정보</strong>에서 <strong>카카오계정(이메일)</strong> 행의 <strong>설정</strong>을 누릅니다.</li>
      <li>동의 단계를 <strong>필수 동의</strong>로 선택하고, 동의 목적에 회원 식별·가입·서비스 메일 발송 등 실제 사용 목적을 적어 저장합니다.</li>
      <li>페이지 아래의 <strong>동의 화면 미리보기</strong>에서 카카오계정(이메일)이 필수 항목으로 표시되는지 확인한 뒤 이 사이트에서 카카오 로그인을 새로 시작합니다.</li>
    </ol>

    <h3>‘필수 동의’를 선택할 수 없는 경우</h3>
    <p>앱 권한이 아직 없을 수 있습니다. 카카오디벨로퍼스의 <strong>앱 → 추가 기능 신청 → 개인정보 동의항목</strong>에서 이메일 권한을 신청하세요. 신청 전 비즈 앱 전환, 신청 자격 확인, 비즈니스 정보 심사가 필요할 수 있습니다.</p>
    <p>심사 자료의 회원가입 화면과 개인정보 처리방침에는 카카오 이메일의 수집 목적, 수집 항목, 필수 여부가 실제 사이트와 동일하게 표시되어야 합니다. 카카오 안내상 심사는 보통 영업일 기준 3~5일이 걸립니다.</p>

    <h3>설정 후에도 이메일이 오지 않는 경우</h3>
    <ul>
      <li>REST API 키와 Client Secret이 같은 카카오 앱의 값인지 확인합니다.</li>
      <li>기존 연결 사용자는 카카오계정의 연결된 서비스 관리에서 앱 연결을 끊은 뒤 다시 로그인하면 새 동의 화면을 확인하기 쉽습니다.</li>
      <li>이 사이트는 이메일이 없거나 유효·인증 상태가 아닌 카카오 이메일로 즉시 가입시키지 않습니다.</li>
    </ul>

    <p class="oauth-guide-links"><a class="link" href="https://developers.kakao.com/docs/ko/kakaologin/prerequisite" target="_blank" rel="noopener noreferrer">카카오 로그인 설정 공식 문서 <span aria-hidden="true">↗</span></a><a class="link" href="https://developers.kakao.com/docs/ko/kakaologin/rest-api" target="_blank" rel="noopener noreferrer">사용자 이메일 응답 공식 문서 <span aria-hidden="true">↗</span></a></p>
  </div>
</details>
