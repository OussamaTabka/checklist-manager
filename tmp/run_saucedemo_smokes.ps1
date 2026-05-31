$ErrorActionPreference = 'Continue'
$root = 'c:\Users\Yahia Ghoufa\Desktop\checklist-manager'
$agent = Join-Path $root 'playwright-agent\dist\python-gen\generateScript.js'
$runner = Join-Path $root 'playwright-agent\dist\python-gen\runLocal.js'
$tmpRoot = Join-Path $root 'tmp\smoke-validation'
New-Item -ItemType Directory -Force -Path $tmpRoot | Out-Null

$cases = @(
  @{
    key = 'TC-001'
    run_id = 'smoke-tc-001'
    external_id = 1
    title = 'TC-001 Standard login'
    description = 'Verify a standard user logs in with the project defaults (standard_user / secret_sauce), reaches /inventory.html, sees the Swag Labs header, and at least one product is visible.'
    checklist_business_rules = @(
      'The default password is secret_sauce for all accounts.',
      'locked_out_user must be rejected.',
      'A successful login redirects to /inventory.html.'
    )
    user_story = @{
      story_id = 'US-001'
      title = 'Authentication'
      as_a = 'Swag Labs customer'
      i_want_that = 'I can log in with my credentials'
      so_that = 'I can reach the catalog and place orders'
      business_rules = 'The default password is secret_sauce for all accounts. locked_out_user must be rejected. A successful login redirects to /inventory.html.'
      priority = 'critical'
    }
    project = @{
      name = 'Sauce Demo E-commerce Automation'
      app_url = 'https://www.saucedemo.com'
      source_app = 'sauce_demo'
    }
    priority = 'High'
    criticality = 'Major'
  }
  @{
    key = 'TC-002'
    run_id = 'smoke-tc-002'
    external_id = 2
    title = 'TC-002 Locked out login'
    description = 'Verify locked_out_user / secret_sauce is rejected, the error message "Epic sadface: Sorry, this user has been locked out." appears, and the user does not reach inventory.'
    checklist_business_rules = @(
      'The default password is secret_sauce for all accounts.',
      'locked_out_user must be rejected.',
      'A successful login redirects to /inventory.html.'
    )
    user_story = @{
      story_id = 'US-001'
      title = 'Authentication'
      as_a = 'Swag Labs customer'
      i_want_that = 'I can log in with my credentials'
      so_that = 'I can reach the catalog and place orders'
      business_rules = 'The default password is secret_sauce for all accounts. locked_out_user must be rejected. A successful login redirects to /inventory.html.'
      priority = 'critical'
    }
    project = @{
      name = 'Sauce Demo E-commerce Automation'
      app_url = 'https://www.saucedemo.com'
      source_app = 'sauce_demo'
    }
    priority = 'High'
    criticality = 'Major'
  }
  @{
    key = 'TC-005'
    run_id = 'smoke-tc-005'
    external_id = 5
    title = 'TC-005 Slow login'
    description = 'Verify performance_glitch_user eventually logs in despite the long delay, which is expected to be around five seconds.'
    checklist_business_rules = @(
      'The default password is secret_sauce for all accounts.',
      'locked_out_user must be rejected.',
      'A successful login redirects to /inventory.html.',
      'performance_glitch_user can take several seconds before the inventory page loads.'
    )
    user_story = @{
      story_id = 'US-001'
      title = 'Authentication'
      as_a = 'Swag Labs customer'
      i_want_that = 'I can log in with my credentials'
      so_that = 'I can reach the catalog and place orders'
      business_rules = 'The default password is secret_sauce for all accounts. locked_out_user must be rejected. A successful login redirects to /inventory.html. performance_glitch_user can take several seconds before the inventory page loads.'
      priority = 'critical'
    }
    project = @{
      name = 'Sauce Demo E-commerce Automation'
      app_url = 'https://www.saucedemo.com'
      source_app = 'sauce_demo'
    }
    priority = 'Medium'
    criticality = 'Minor'
  }
)

$results = @()
foreach ($case in $cases) {
  $caseDir = Join-Path $tmpRoot $case.key
  New-Item -ItemType Directory -Force -Path $caseDir | Out-Null
  $inputPath = Join-Path $caseDir 'input.json'
  $specPath = Join-Path $caseDir 'run-spec.json'
  $resultDir = Join-Path $caseDir 'result'
  $generateLog = Join-Path $caseDir 'generate.log'
  $runLog = Join-Path $caseDir 'run.log'

  $input = @{
    run_id = $case.run_id
    external_id = $case.external_id
    test_case_title = $case.title
    test_case_description = $case.description
    test_case_text = "$($case.title)`n$($case.description)"
    base_url = 'https://www.saucedemo.com'
    use_auth = $false
    environment_name = 'sauce-demo'
    notes = 'Smoke validation of prompt-based credential inference.'
    priority = $case.priority
    criticality = $case.criticality
    current_status = 'pending'
    target_type = 'checklist_item'
    source_app = 'sauce_demo'
    user_story = $case.user_story
    checklist_business_rules = $case.checklist_business_rules
    project = $case.project
    provided_inputs = @{}
    expected_result = @{}
  }

  ($input | ConvertTo-Json -Depth 20) | Set-Content -Encoding utf8 $inputPath
  & node $agent --input $inputPath 1> $specPath 2> $generateLog
  if ($LASTEXITCODE -ne 0) {
    throw "generateScript failed for $($case.key). See $generateLog"
  }

  & node $runner --run-spec $specPath --headless --result-dir $resultDir 1> (Join-Path $caseDir 'run.stdout.json') 2> $runLog
  $runExit = $LASTEXITCODE

  $runSpec = Get-Content $specPath -Raw | ConvertFrom-Json
  $resultPath = Join-Path $resultDir 'result.json'
  if (Test-Path $resultPath) {
    $result = Get-Content $resultPath -Raw | ConvertFrom-Json
  } else {
    $result = Get-Content (Join-Path $caseDir 'run.stdout.json') -Raw | ConvertFrom-Json
  }
  $caseResult = $result.results[0]
  $scriptBody = [string]$runSpec.cases[0].python_script_body
  $snippet = ($scriptBody -split "`r?`n" | Where-Object { $_.Trim() } | Select-Object -First 1)
  if (-not $snippet) { $snippet = $scriptBody.Substring(0, [Math]::Min(120, $scriptBody.Length)) }
  $results += [pscustomobject]@{
    run_id = $result.run_id
    status = $caseResult.status
    error_type = $caseResult.error_type
    snippet = $snippet.Trim()
    runner_exit = $runExit
  }
}

$results | Format-Table -AutoSize
Write-Output ($results | ConvertTo-Json -Depth 5)