import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(path.dirname(new URL(import.meta.url).pathname.replace(/^\/(.:)/, '$1')), '../..');
const rules = fs.readFileSync(path.join(root, 'docs/plans/vibe-coding-collaboration-rules.md'), 'utf8');

assert.match(rules, /正式目标模式编程任务[\s\S]{0,500}ARCH-/, 'formal target-mode work must require a real ARCH track');
assert.match(rules, /正式目标模式编程任务[\s\S]{0,700}DEV-/, 'formal target-mode work must require a user-visible or executable DEV track');
assert.match(rules, /验收[\s\S]{0,180}(不能|不得)[\s\S]{0,100}(唯一|整个任务)/, 'acceptance alone must not become a normal target-mode task');
assert.match(rules, /安全 Gate[\s\S]{0,400}产品决定[\s\S]{0,400}(外部授权|环境)[\s\S]{0,400}越界/, 'pure acceptance or docs exceptions must stay explicitly gated');
assert.match(rules, /复杂度[\s\S]{0,160}(预算|执行预算)[\s\S]{0,160}(不扩大|不得扩大)/, 'complexity must not expand scope');
assert.match(rules, /(无意义|不必要)[\s\S]{0,120}(抽象|DTO|Repository|Interface|Adapter)/, 'ARCH + DEV must not justify invented abstractions');
assert.match(rules, /完成当前复合任务[\s\S]{0,180}(停止|不能自动进入下一任务)/, 'the local agent must stop after the composite task');

console.log('Target-mode composite task rule contract passed.');
