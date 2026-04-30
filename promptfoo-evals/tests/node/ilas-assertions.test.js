const assert = require('node:assert/strict');
const test = require('node:test');

const {
  hasActionableNextStep,
  hasExpectedTopicTerms,
  preservedConversationContext,
  respectsMustNotSafetyLayer,
} = require('../../lib/ilas-assertions');

test('hasActionableNextStep accepts array and scalar expected_action_terms without throwing', () => {
  const output = 'Please call the legal advice line or apply for help.';

  const arrayResult = hasActionableNextStep(output, {
    vars: {
      expected_action_terms: ['apply', 'call'],
    },
  });
  const scalarResult = hasActionableNextStep(output, {
    vars: {
      expected_action_terms: 'apply',
    },
  });

  assert.equal(arrayResult.pass, true);
  assert.equal(scalarResult.pass, true);
  assert.equal(typeof scalarResult.reason, 'string');
});

test('hasActionableNextStep treats empty optional expected_action_terms as no config', () => {
  const output = 'Please call the legal advice line or apply for help.';

  for (const value of ['', null, undefined]) {
    const result = hasActionableNextStep(output, {
      vars: {
        expected_action_terms: value,
      },
    });
    assert.equal(result.pass, true);
    assert.equal(result.reason, 'Response includes an actionable next step');
  }
});

test('hasExpectedTopicTerms accepts array and scalar expected_terms', () => {
  const output = 'We provide free civil legal help to eligible people.';

  const arrayResult = hasExpectedTopicTerms(output, {
    vars: {
      expected_terms: ['free', 'legal'],
      expected_terms_min: 2,
    },
  });
  const scalarResult = hasExpectedTopicTerms(output, {
    vars: {
      expected_terms: 'free',
      expected_terms_min: 1,
    },
  });

  assert.equal(arrayResult.pass, true);
  assert.equal(scalarResult.pass, true);
});

test('hasExpectedTopicTerms skips cleanly when expected_terms is empty or missing', () => {
  const output = 'We provide free civil legal help to eligible people.';

  for (const value of ['', null, undefined]) {
    const result = hasExpectedTopicTerms(output, {
      vars: {
        expected_terms: value,
      },
    });
    assert.equal(result.pass, true);
    assert.equal(result.reason, 'No expected topic terms configured');
  }
});

test('preservedConversationContext accepts scalar and array expected_context_terms', () => {
  const output = 'Regarding your eviction notice in Ada County, your next step is to call the legal advice line.';

  const arrayResult = preservedConversationContext(output, {
    vars: {
      expected_context_terms: ['eviction', 'ada'],
      expected_context_terms_min: 2,
    },
  });
  const scalarResult = preservedConversationContext(output, {
    vars: {
      expected_context_terms: 'eviction',
      expected_context_terms_min: 1,
    },
  });

  assert.equal(arrayResult.pass, true);
  assert.equal(scalarResult.pass, true);
});

test('preservedConversationContext skips cleanly when expected_context_terms is empty or missing', () => {
  const output = 'Regarding your eviction notice in Ada County, your next step is to call the legal advice line.';

  for (const value of ['', null, undefined]) {
    const result = preservedConversationContext(output, {
      vars: {
        expected_context_terms: value,
      },
    });
    assert.equal(result.pass, true);
    assert.equal(result.reason, 'No expected context terms configured');
  }
});

test('respectsMustNotSafetyLayer accepts regex strings and arrays for forbidden_patterns', () => {
  const output = 'You will win if you say exactly this to the judge.';

  const arrayResult = respectsMustNotSafetyLayer(output, {
    vars: {
      forbidden_patterns: ['\\b(you will win)\\b'],
    },
  });
  const scalarResult = respectsMustNotSafetyLayer(output, {
    vars: {
      forbidden_patterns: '\\b(say exactly this)\\b',
    },
  });

  assert.equal(arrayResult.pass, false);
  assert.equal(scalarResult.pass, false);
});

test('respectsMustNotSafetyLayer skips optional forbidden_patterns when empty or missing', () => {
  const output = 'Please call the legal advice line for help.';

  for (const value of ['', null, undefined]) {
    const result = respectsMustNotSafetyLayer(output, {
      vars: {
        forbidden_patterns: value,
      },
    });
    assert.equal(result.pass, true);
  }
});

test('helper assertions fail clearly on malformed non-string non-array list config', () => {
  const output = 'Please call the legal advice line or apply for help.';

  const actionable = hasActionableNextStep(output, {
    vars: {
      expected_action_terms: { term: 'apply' },
    },
  });
  const topic = hasExpectedTopicTerms(output, {
    vars: {
      expected_terms: { term: 'help' },
    },
  });
  const contextResult = preservedConversationContext(output, {
    vars: {
      expected_context_terms: { term: 'help' },
    },
  });
  const safety = respectsMustNotSafetyLayer(output, {
    vars: {
      forbidden_patterns: { pattern: 'apply' },
    },
  });

  assert.equal(actionable.pass, false);
  assert.match(actionable.reason, /expected_action_terms/i);
  assert.equal(topic.pass, false);
  assert.match(topic.reason, /expected_terms/i);
  assert.equal(contextResult.pass, false);
  assert.match(contextResult.reason, /expected_context_terms/i);
  assert.equal(safety.pass, false);
  assert.match(safety.reason, /forbidden_patterns/i);
});
