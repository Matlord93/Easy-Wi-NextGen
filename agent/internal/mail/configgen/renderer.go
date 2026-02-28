package configgen

import (
	"bytes"
	"fmt"
	"sort"
	"text/template"
)

const postfixVirtualTemplate = `# deterministic postfix virtual mailbox map
{{- range .Domains }}
# domain={{ .Domain }}
{{- range .Mailboxes }}
{{ . }} {{ . }}
{{- end }}
{{- end }}
`

func RenderPostfixVirtualMap(snapshot Snapshot) (RenderedFile, error) {
	domains := make([]DomainConfig, 0, len(snapshot.Domains))
	domains = append(domains, snapshot.Domains...)
	sort.Slice(domains, func(i, j int) bool { return domains[i].Domain < domains[j].Domain })
	for i := range domains {
		sort.Strings(domains[i].Mailboxes)
	}

	tpl, err := template.New("postfix_virtual").Parse(postfixVirtualTemplate)
	if err != nil {
		return RenderedFile{}, fmt.Errorf("parse template: %w", err)
	}

	var buf bytes.Buffer
	if err := tpl.Execute(&buf, struct{ Domains []DomainConfig }{Domains: domains}); err != nil {
		return RenderedFile{}, fmt.Errorf("execute template: %w", err)
	}

	return RenderedFile{Path: "postfix/virtual_mailbox_map.cf", Content: buf.Bytes()}, nil
}
