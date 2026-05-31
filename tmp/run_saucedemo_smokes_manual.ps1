$ErrorActionPreference = 'Continue'
$root = 'c:\Users\Yahia Ghoufa\Desktop\checklist-manager'
$wrapper = Join-Path $root 'python-runner\wrapper.py'
$tmpRoot = Join-Path $root 'tmp\smoke-validation-manual'
New-Item -ItemType Directory -Force -Path $tmpRoot | Out-Null

$cases = @(
  @{
    key = 'TC-001'
    run_id = 'smoke-tc-001-local'
    external_id = 1
    title = 'TC-001 Standard login'
    script = @(
      "username = inputs.get('username', 'standard_user')",
      "password = inputs.get('password', 'secret_sauce')",
      '',
      "await page.locator('#user-name').fill(username)",
      "await page.locator('#password').fill(password)",
      "await page.locator('#login-button').click()",
      '',
      "await page.wait_for_url('**/inventory.html', timeout=30000)",
      "await expect(page.locator('.app_logo')).to_contain_text('Swag Labs')",
      "await expect(page.locator('.inventory_item')).to_have_count(6)"
    ) -join "`n"
    snippet = "username = inputs.get('username', 'standard_user')"
  }
  @{
    key = 'TC-002'
    run_id = 'smoke-tc-002-local'
    external_id = 2
    title = 'TC-002 Locked out login'
    script = @(
      "username = inputs.get('username', 'locked_out_user')",
      "password = inputs.get('password', 'secret_sauce')",
      '',
      "await page.locator('#user-name').fill(username)",
      "await page.locator('#password').fill(password)",
      "await page.locator('#login-button').click()",
      '',
      "await expect(page.locator('[data-test=""error""]')).to_contain_text('locked out')",
      "await expect(page).not_to_have_url('**/inventory.html')"
    ) -join "`n"
    snippet = "username = inputs.get('username', 'locked_out_user')"
  }
  @{
    key = 'TC-005'
    run_id = 'smoke-tc-005-local'
    external_id = 5
    title = 'TC-005 Slow login'
    script = @(
      "username = inputs.get('username', 'performance_glitch_user')",
      "password = inputs.get('password', 'secret_sauce')",
      '',
      "await page.locator('#user-name').fill(username)",
      "await page.locator('#password').fill(password)",
      "await page.locator('#login-button').click()",
      '',
      "await page.wait_for_url('**/inventory.html', timeout=45000)",
      "await expect(page.locator('.app_logo')).to_contain_text('Swag Labs')"
    ) -join "`n"
    snippet = "username = inputs.get('username', 'performance_glitch_user')"
  }
)

$results = @()
foreach ($case in $cases) {
  $caseDir = Join-Path $tmpRoot $case.key
  New-Item -ItemType Directory -Force -Path $caseDir | Out-Null
  $specPath = Join-Path $caseDir 'run-spec.json'
  $resultDir = Join-Path $caseDir 'result'
  $runLog = Join-Path $caseDir 'run.log'
  $resultPath = Join-Path $resultDir 'result.json'
  $artifactDir = Join-Path $resultDir 'artifacts'
  New-Item -ItemType Directory -Force -Path $resultDir | Out-Null
  New-Item -ItemType Directory -Force -Path $artifactDir | Out-Null

  $runSpec = @{
    schema_version = '1.0'
    run_id = $case.run_id
    generation_mode = 'python_script'
    target = @{ base_url = 'https://www.saucedemo.com' }
    runtime = @{ headless = $true; slow_mo_ms = 0; hold_open_ms = 0; timeout_ms = 45000; viewport = @{ width = 1280; height = 720 }; trace = 'off'; video = 'off'; screenshot = 'off' }
    generation_metadata = @{ engine = 'manual-fallback'; requested_engine = 'none' }
    cases = @(
      @{
        external_id = $case.external_id
        title = $case.title
        severity = 'major'
        use_auth = $false
        execution_profile = @{
          intent_summary = 'Manual smoke validation fallback for the Sauce Demo login flow.'
          coverage_type = 'auth_login'
          preconditions = @('Target base URL must be reachable: https://www.saucedemo.com', 'The login page or authentication form must be available on the target website.')
          required_inputs = @(
            @{ key = 'username'; label = 'Username'; kind = 'text'; required = $true; description = 'Account username.' },
            @{ key = 'password'; label = 'Password'; kind = 'password'; required = $true; description = 'Account password.' }
          )
          expected_observations = @('The login outcome described by the case title should occur.')
          diagnostics = @()
        }
        python_script_body = $case.script
        human_readable_steps = @('Open the Sauce Demo login page.', 'Submit the credentials for the target case.', 'Verify the expected login outcome.')
        provided_inputs = @{}
        preflight_checks = @()
        steps = @(@{ action = 'manual-fallback' })
        asserts = @()
      }
    )
  }

  $runSpecJson = $runSpec | ConvertTo-Json -Depth 20
  [System.IO.File]::WriteAllText($specPath, $runSpecJson, (New-Object System.Text.UTF8Encoding($false)))
  $env:RUN_JSON_PATH = $specPath
  $env:RESULT_JSON_PATH = $resultPath
  $env:ARTIFACTS_DIR = $artifactDir
  & python $wrapper 1> (Join-Path $caseDir 'run.stdout.json') 2> $runLog
  $runExit = $LASTEXITCODE

  if (Test-Path $resultPath) {
    $result = Get-Content $resultPath -Raw | ConvertFrom-Json
  } else {
    $result = Get-Content (Join-Path $caseDir 'run.stdout.json') -Raw | ConvertFrom-Json
  }
  $caseResult = $result.results[0]
  $results += [pscustomobject]@{
    run_id = $result.run_id
    status = $caseResult.status
    error_type = $caseResult.error_type
    snippet = $case.snippet
    runner_exit = $runExit
  }
}

$results | Format-Table -AutoSize
Write-Output ($results | ConvertTo-Json -Depth 5)