function escapeRegExp(value) {
  return String(value || "").replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
}

function resolveTranslator(options) {
  if (typeof options?.t === "function") {
    return options.t;
  }

  return (_key, _params, fallback) => String(fallback || _key || "");
}

function countMatches(pattern, source) {
  const text = String(source || "");
  const regex = new RegExp(pattern.source, pattern.flags.includes("g") ? pattern.flags : `${pattern.flags}g`);
  const matches = text.match(regex);
  return Array.isArray(matches) ? matches.length : 0;
}

function stripCommentsAndStrings(source) {
  let text = String(source || "");

  // Strip block and line comments first.
  text = text.replace(/\/\*[\s\S]*?\*\//g, " ");
  text = text.replace(/\/\/.*$/gm, " ");
  text = text.replace(/#.*$/gm, " ");

  // Strip string literals to reduce false positives while counting patterns.
  text = text.replace(/"(?:\\.|[^"\\])*"/g, "\"\"");
  text = text.replace(/'(?:\\.|[^'\\])*'/g, "''");
  text = text.replace(/`(?:\\.|[^`\\])*`/g, "``");

  return text;
}

function detectLanguageFamily(languageInput, filePathInput) {
  const language = String(languageInput || "").trim().toLowerCase();
  const path = String(filePathInput || "").trim().toLowerCase();

  if (language === "php" || path.endsWith(".php")) {
    return "php";
  }

  if (language === "python" || path.endsWith(".py")) {
    return "python";
  }

  if (language === "typescript" || path.endsWith(".ts") || path.endsWith(".tsx")) {
    return "typescript";
  }

  if (language === "javascript" || path.endsWith(".js") || path.endsWith(".jsx")) {
    return "javascript";
  }

  if (language === "java" || path.endsWith(".java")) {
    return "java";
  }

  return "generic";
}

function detectFunctionNames(sanitizedCode) {
  const names = new Set();

  let match;
  const namedFunctionPattern = /\bfunction\s+([A-Za-z_]\w*)\s*\(/g;
  while ((match = namedFunctionPattern.exec(sanitizedCode)) !== null) {
    names.add(match[1]);
  }

  const assignedArrowPattern = /\b(?:const|let|var)\s+([A-Za-z_]\w*)\s*=\s*(?:async\s*)?\([^)]*\)\s*=>/g;
  while ((match = assignedArrowPattern.exec(sanitizedCode)) !== null) {
    names.add(match[1]);
  }

  const pythonDefPattern = /\bdef\s+([A-Za-z_]\w*)\s*\(/g;
  while ((match = pythonDefPattern.exec(sanitizedCode)) !== null) {
    names.add(match[1]);
  }

  return Array.from(names);
}

function detectLoopDepth(sanitizedCode) {
  const tokenPattern = /\b(for|while)\b|[{}]/g;
  const loopDepthStack = [];
  let braceDepth = 0;
  let loopCount = 0;
  let maxDepth = 0;
  let token;

  while ((token = tokenPattern.exec(sanitizedCode)) !== null) {
    const item = token[0];
    if (item === "{") {
      braceDepth += 1;
      continue;
    }

    if (item === "}") {
      braceDepth = Math.max(0, braceDepth - 1);
      while (loopDepthStack.length > 0 && loopDepthStack[loopDepthStack.length - 1] > braceDepth) {
        loopDepthStack.pop();
      }
      continue;
    }

    loopCount += 1;
    loopDepthStack.push(braceDepth + 1);
    maxDepth = Math.max(maxDepth, loopDepthStack.length);
  }

  // Fallback for brace-less loops.
  if (loopCount > 0 && maxDepth === 0) {
    maxDepth = 1;
  }

  return {
    loopCount,
    maxDepth,
  };
}

function detectRecursion(sanitizedCode) {
  const functionNames = detectFunctionNames(sanitizedCode);
  const recursiveFunctions = [];
  let branchingRecursionSignals = 0;

  functionNames.forEach((name) => {
    const callRegex = new RegExp(`\\b${escapeRegExp(name)}\\s*\\(`, "g");
    const totalCalls = countMatches(callRegex, sanitizedCode);

    if (totalCalls > 1) {
      recursiveFunctions.push(name);

      const linePattern = new RegExp(`\\b${escapeRegExp(name)}\\s*\\(`, "g");
      const lines = sanitizedCode.split(/\r?\n/);
      lines.forEach((line) => {
        const lineMatches = line.match(linePattern);
        if (Array.isArray(lineMatches) && lineMatches.length >= 2) {
          branchingRecursionSignals += 1;
        }
      });
    }
  });

  return {
    recursiveFunctions,
    branchingRecursionSignals,
  };
}

function estimateTimeComplexity(metrics) {
  const {
    loopCount,
    maxLoopDepth,
    recursionCount,
    branchingRecursionSignals,
    sortCalls,
    logarithmicLoopSignals,
  } = metrics;

  if (branchingRecursionSignals > 0) {
    return "O(2^n)";
  }

  if (recursionCount > 0 && loopCount > 0 && maxLoopDepth >= 1) {
    return "O(n^2)";
  }

  if (recursionCount > 0) {
    return "O(n)";
  }

  if (maxLoopDepth >= 3) {
    return "O(n^3)";
  }

  if (maxLoopDepth === 2) {
    return "O(n^2)";
  }

  if (sortCalls > 0 && maxLoopDepth <= 1) {
    return loopCount > 0 ? "O(n log n)" : "O(n log n)";
  }

  if (loopCount > 0 && logarithmicLoopSignals > 0) {
    return loopCount > 1 ? "O(n log n)" : "O(log n)";
  }

  if (loopCount > 0) {
    return "O(n)";
  }

  return "O(1)";
}

function estimateSpaceComplexity(metrics) {
  const {
    recursionCount,
    collectionInitializations,
    dynamicAppendOps,
    comprehensionSignals,
  } = metrics;

  if (recursionCount > 0) {
    return "O(n)";
  }

  if (collectionInitializations > 0 || dynamicAppendOps > 0 || comprehensionSignals > 0) {
    return "O(n)";
  }

  return "O(1)";
}

function buildComplexitySignals(metrics, timeComplexity, spaceComplexity, options = {}) {
  const t = resolveTranslator(options);
  const signals = [];

  if (metrics.maxLoopDepth > 0) {
    signals.push(t(
      "editor.projectTestingSignalNestedLoops",
      { depth: metrics.maxLoopDepth },
      `Nested loops detected: depth ${metrics.maxLoopDepth}.`,
    ));
  }

  if (metrics.recursionCount > 0) {
    const names = metrics.recursiveFunctions.slice(0, 3).join(", ");
    if (names) {
      signals.push(t(
        "editor.projectTestingSignalRecursionWithNames",
        { names },
        `Recursive calls detected in: ${names}.`,
      ));
    } else {
      signals.push(t(
        "editor.projectTestingSignalRecursion",
        {},
        "Recursive calls detected.",
      ));
    }
  }

  if (metrics.sortCalls > 0) {
    signals.push(t(
      "editor.projectTestingSignalSort",
      {},
      "Sorting calls detected.",
    ));
  }

  if (metrics.logarithmicLoopSignals > 0) {
    signals.push(t(
      "editor.projectTestingSignalLogLoop",
      {},
      "Logarithmic loop progression detected (e.g. *= 2, /= 2).",
    ));
  }

  if (metrics.dynamicAppendOps > 0 || metrics.collectionInitializations > 0) {
    signals.push(t(
      "editor.projectTestingSignalCollectionGrowth",
      {},
      "Dynamic collection growth detected, memory may scale with input.",
    ));
  }

  if (signals.length === 0) {
    signals.push(t(
      "editor.projectTestingSignalNoPatterns",
      {},
      "No major loop/recursion/sort patterns detected.",
    ));
  }

  signals.push(t(
    "editor.projectTestingSignalEstimatedTime",
    { value: timeComplexity },
    `Estimated time: ${timeComplexity}.`,
  ));
  signals.push(t(
    "editor.projectTestingSignalEstimatedSpace",
    { value: spaceComplexity },
    `Estimated space: ${spaceComplexity}.`,
  ));

  return signals;
}

function resolveConfidence(metrics, languageFamily) {
  let confidence = 36;

  if (metrics.loopCount > 0) {
    confidence += 16;
  }
  if (metrics.maxLoopDepth > 1) {
    confidence += 10;
  }
  if (metrics.recursionCount > 0) {
    confidence += 12;
  }
  if (metrics.sortCalls > 0) {
    confidence += 10;
  }
  if (metrics.branchPoints > 0) {
    confidence += 5;
  }
  if (languageFamily !== "generic") {
    confidence += 8;
  }

  return Math.max(25, Math.min(95, confidence));
}

export function analyzeCodeComplexity(sourceCodeInput, options = {}) {
  const sourceCode = String(sourceCodeInput || "");
  const languageFamily = detectLanguageFamily(options.language, options.filePath);
  const sanitizedCode = stripCommentsAndStrings(sourceCode);
  const hasInput = sanitizedCode.trim() !== "";

  if (!hasInput) {
    return {
      empty: true,
      languageFamily,
      timeComplexity: "O(1)",
      spaceComplexity: "O(1)",
      confidence: 0,
      metrics: {
        loopCount: 0,
        maxLoopDepth: 0,
        recursionCount: 0,
        recursiveFunctions: [],
        branchPoints: 0,
        cyclomaticComplexity: 1,
        sortCalls: 0,
        logarithmicLoopSignals: 0,
        collectionInitializations: 0,
        dynamicAppendOps: 0,
        comprehensionSignals: 0,
      },
      signals: [],
    };
  }

  const loopMetrics = detectLoopDepth(sanitizedCode);
  const recursionMetrics = detectRecursion(sanitizedCode);
  const sortCalls = countMatches(/\b(?:sort|sorted|Arrays\.sort|Collections\.sort)\s*\(/g, sanitizedCode);
  const logarithmicLoopSignals = countMatches(/(?:\*=|\/=|>>=|<<=)\s*2\b|\b\w+\s*=\s*\w+\s*[*/]\s*2\b/g, sanitizedCode);
  const branchPoints = countMatches(/\bif\b|\belse\s+if\b|\bswitch\b|\bcase\b|\bcatch\b|\?\s*[^:]+:/g, sanitizedCode)
    + countMatches(/&&|\|\|/g, sanitizedCode);
  const collectionInitializations = countMatches(
    /\b(?:new\s+Array|new\s+Map|new\s+Set|new\s+Object)\b|\b(?:const|let|var)\s+\w+\s*=\s*\[\s*\]|\b(?:const|let|var)\s+\w+\s*=\s*\{\s*\}|\b\w+\s*=\s*\[\s*\]|\b\w+\s*=\s*\{\s*\}/g,
    sanitizedCode,
  );
  const dynamicAppendOps = countMatches(/\b(?:push|append|add|extend|insert|unshift)\s*\(/g, sanitizedCode);
  const comprehensionSignals = countMatches(/\[[^\]]+\bfor\b[^\]]+\]|\{[^}]+\bfor\b[^}]+}/g, sanitizedCode);

  const metrics = {
    loopCount: loopMetrics.loopCount,
    maxLoopDepth: loopMetrics.maxDepth,
    recursionCount: recursionMetrics.recursiveFunctions.length,
    recursiveFunctions: recursionMetrics.recursiveFunctions,
    branchingRecursionSignals: recursionMetrics.branchingRecursionSignals,
    branchPoints,
    cyclomaticComplexity: Math.max(1, 1 + branchPoints),
    sortCalls,
    logarithmicLoopSignals,
    collectionInitializations,
    dynamicAppendOps,
    comprehensionSignals,
  };

  const timeComplexity = estimateTimeComplexity(metrics);
  const spaceComplexity = estimateSpaceComplexity(metrics);
  const confidence = resolveConfidence(metrics, languageFamily);
  const signals = buildComplexitySignals(metrics, timeComplexity, spaceComplexity, options);

  return {
    empty: false,
    languageFamily,
    timeComplexity,
    spaceComplexity,
    confidence,
    metrics,
    signals,
  };
}

function dedupeScenarios(items) {
  const seen = new Set();
  return items.filter((item) => {
    const key = `${item.input}|${item.expectation}`;
    if (seen.has(key)) {
      return false;
    }
    seen.add(key);
    return true;
  });
}

function detectThresholds(sanitizedCode) {
  const thresholds = [];
  const thresholdRegex = /\b([A-Za-z_]\w*)\s*(<=|>=|<|>|===|==|!=)\s*(-?\d+(?:\.\d+)?)\b/g;
  let match;

  while ((match = thresholdRegex.exec(sanitizedCode)) !== null) {
    thresholds.push({
      variable: match[1],
      operator: match[2],
      value: Number(match[3]),
    });
  }

  return thresholds;
}

export function buildWhiteBoxChecklist(analysisResult, options = {}) {
  const t = resolveTranslator(options);
  const analysis = analysisResult || {};
  const metrics = analysis.metrics || {};
  const cyclomatic = Math.max(1, Number(metrics.cyclomaticComplexity || 1));
  const loopDepth = Math.max(0, Number(metrics.maxLoopDepth || 0));
  const recursionCount = Math.max(0, Number(metrics.recursionCount || 0));

  const checks = [
    t(
      "editor.projectTestingWhiteCheckBranches",
      {},
      "Cover each branch outcome at least once (true/false paths).",
    ),
    t(
      "editor.projectTestingWhiteCheckErrors",
      {},
      "Validate error handling path and unexpected input flow.",
    ),
  ];

  if (loopDepth > 0) {
    checks.push(t(
      "editor.projectTestingWhiteCheckLoops",
      {},
      "Add loop boundary tests: 0 iterations, 1 iteration, and typical multi-iteration path.",
    ));
  }

  if (recursionCount > 0) {
    checks.push(t(
      "editor.projectTestingWhiteCheckRecursion",
      {},
      "Validate recursion base case and one non-base recursive path.",
    ));
  }

  return {
    cyclomaticComplexity: cyclomatic,
    recommendedMinTests: cyclomatic,
    checks,
  };
}

export function buildBlackBoxScenarios(sourceCodeInput, options = {}) {
  const t = resolveTranslator(options);
  const sanitizedCode = stripCommentsAndStrings(sourceCodeInput);
  const thresholds = detectThresholds(sanitizedCode);
  const scenarios = [
    {
      type: "nominal",
      input: t(
        "editor.projectTestingScenarioNominalInput",
        {},
        "Representative valid input",
      ),
      expectation: t(
        "editor.projectTestingScenarioNominalExpectation",
        {},
        "Returns successful output for typical user flow.",
      ),
    },
    {
      type: "empty",
      input: t(
        "editor.projectTestingScenarioEmptyInput",
        {},
        "Empty or minimal input",
      ),
      expectation: t(
        "editor.projectTestingScenarioEmptyExpectation",
        {},
        "Handles empty input without crashing.",
      ),
    },
    {
      type: "invalid",
      input: t(
        "editor.projectTestingScenarioInvalidInput",
        {},
        "Invalid type or malformed payload",
      ),
      expectation: t(
        "editor.projectTestingScenarioInvalidExpectation",
        {},
        "Rejects or safely handles invalid payload.",
      ),
    },
  ];

  thresholds.slice(0, 3).forEach((threshold) => {
    const nearLow = Number.isFinite(threshold.value) ? threshold.value - 1 : threshold.value;
    const nearHigh = Number.isFinite(threshold.value) ? threshold.value + 1 : threshold.value;

    scenarios.push({
      type: "boundary-low",
      input: `${threshold.variable}=${nearLow}`,
      expectation: t(
        "editor.projectTestingScenarioBoundaryExpectation",
        {
          variable: threshold.variable,
          operator: threshold.operator,
          value: threshold.value,
        },
        `Boundary behavior near condition ${threshold.variable} ${threshold.operator} ${threshold.value}.`,
      ),
    });
    scenarios.push({
      type: "boundary-high",
      input: `${threshold.variable}=${nearHigh}`,
      expectation: t(
        "editor.projectTestingScenarioBoundaryExpectation",
        {
          variable: threshold.variable,
          operator: threshold.operator,
          value: threshold.value,
        },
        `Boundary behavior near condition ${threshold.variable} ${threshold.operator} ${threshold.value}.`,
      ),
    });
  });

  return dedupeScenarios(scenarios);
}

export function buildAutomationCommands(options = {}) {
  const t = resolveTranslator(options);
  const languageFamily = detectLanguageFamily(options.language, options.filePath);

  if (languageFamily === "php") {
    return {
      whiteBox: [
        "php artisan test --testsuite=Unit --stop-on-failure",
        "php artisan test --filter <TargetClassOrMethod>",
      ],
      blackBox: [
        "php artisan test --testsuite=Feature",
        "php artisan test --filter <HttpOrApiFlow>",
      ],
      coverage: "php -d xdebug.mode=coverage artisan test --coverage-text",
    };
  }

  if (languageFamily === "javascript" || languageFamily === "typescript") {
    return {
      whiteBox: [
        "npm run test -- --watch=false",
        "npm run test -- --findRelatedTests <file>",
      ],
      blackBox: [
        "npm run test:e2e",
        "npm run test -- --runInBand",
      ],
      coverage: "npm run test -- --coverage",
    };
  }

  if (languageFamily === "python") {
    return {
      whiteBox: [
        "pytest -q tests/unit",
        "pytest -q -k <target_function>",
      ],
      blackBox: [
        "pytest -q tests/integration",
        "pytest -q tests/e2e",
      ],
      coverage: "pytest --cov=. --cov-report=term-missing",
    };
  }

  return {
    whiteBox: [
      t(
        "editor.projectTestingAutomationGenericWhite1",
        {},
        "Run unit tests with branch coverage enabled.",
      ),
      t(
        "editor.projectTestingAutomationGenericWhite2",
        {},
        "Filter tests to the changed module and verify each branch path.",
      ),
    ],
    blackBox: [
      t(
        "editor.projectTestingAutomationGenericBlack1",
        {},
        "Run integration/end-to-end tests for user-facing flows.",
      ),
      t(
        "editor.projectTestingAutomationGenericBlack2",
        {},
        "Add boundary and invalid-input scenarios to regression suite.",
      ),
    ],
    coverage: t(
      "editor.projectTestingAutomationGenericCoverage",
      {},
      "Enable coverage report in your primary test runner.",
    ),
  };
}
