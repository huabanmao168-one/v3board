import { Button, Input, Select } from 'antd'
import { fetchConfig, saveConfig } from '@/api/services/config'
import { RulesSection, useRules } from './shared'

const matches = [
  { value: 'all', label: '全部订阅' },
  { value: 'ua_contains', label: 'UA 包含' },
  { value: 'ua_not_contains', label: 'UA 不包含' },
  { value: 'email_exact', label: '邮箱等于' },
  { value: 'email_suffix', label: '邮箱后缀' },
  { value: 'email_keyword', label: '邮箱包含' },
]

interface DisplayRule {
  match: string
  value?: string
  title?: string
  replacements: Array<[string, string]>
}

async function loadRules() {
  const result = await fetchConfig('subscribe')
  const stored = result.data?.subscribe?.subscribe_display_rules
  const data: DisplayRule[] = typeof stored === 'string' && stored
    ? (JSON.parse(stored) as Array<Omit<DisplayRule, 'replacements'> & { replacements?: Record<string, string> }>).map((rule) => ({
        ...rule,
        replacements: Object.entries(rule.replacements ?? {}),
      }))
    : []
  return { ...result, data }
}

function saveRules(rules: DisplayRule[]) {
  return saveConfig({
    subscribe_display_rules: JSON.stringify(rules.map(({ replacements, ...rule }) => ({
      ...rule,
      replacements: Object.fromEntries(replacements.filter(([from]) => from.trim() !== '')),
    }))),
  })
}

export function DisplayRules() {
  const { rules, loading, failed, saving, update, remove, add, submit } = useRules(loadRules, saveRules)
  return (
    <RulesSection
      title="订阅展示"
      description="按顺序应用第一条匹配的规则。标题和名称替换都可留空。"
      loading={loading}
      failed={failed}
      saving={saving}
      onAdd={() => add({ match: 'all', replacements: [] })}
      onSave={() => void submit()}
    >
      {rules.map((rule, index) => (
        <div className="border rounded p-3 mb-3" key={index}>
          <div className="d-flex flex-wrap" style={{ gap: 8 }}>
            <Select aria-label="匹配条件" style={{ width: 136 }} options={matches} value={rule.match} onChange={(match) => update(index, { match })} />
            {rule.match !== 'all' && <Input aria-label="匹配内容" style={{ width: 200 }} placeholder="匹配内容" value={rule.value ?? ''} onChange={(event) => update(index, { value: event.target.value })} />}
            <Input aria-label="新订阅标题" style={{ width: 200 }} placeholder="新订阅标题" value={rule.title ?? ''} onChange={(event) => update(index, { title: event.target.value })} />
            <Button danger onClick={() => remove(index)}>删除规则</Button>
          </div>
          {rule.replacements.map(([from, to], row) => (
            <div className="d-flex flex-wrap mt-2" style={{ gap: 8 }} key={row}>
              <Input placeholder="原名称关键词" aria-label="原名称关键词" style={{ width: 180 }} value={from} onChange={(event) => update(index, { replacements: rule.replacements.map((pair, i) => i === row ? [event.target.value, to] : pair) })} />
              <Input placeholder="替换为" aria-label="替换为" style={{ width: 180 }} value={to} onChange={(event) => update(index, { replacements: rule.replacements.map((pair, i) => i === row ? [from, event.target.value] : pair) })} />
              <Button onClick={() => update(index, { replacements: rule.replacements.filter((_, i) => i !== row) })}>移除替换</Button>
            </div>
          ))}
          <Button className="mt-2" onClick={() => update(index, { replacements: [...rule.replacements, ['', '']] })}>添加名称替换</Button>
        </div>
      ))}
    </RulesSection>
  )
}
