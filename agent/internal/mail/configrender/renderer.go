package configrender

import (
	"bytes"
	"sort"
	"strings"
	"text/template"
	"time"
)

type Renderer struct{}

func NewRenderer() *Renderer { return &Renderer{} }

func (r *Renderer) Render(snapshot Snapshot) (RenderBundle, error) {
	normalized := normalizeSnapshot(snapshot)
	files := make([]FileSpec, 0, 7)

	postfixMain, err := execTemplate(postfixMainTpl, normalized)
	if err != nil {
		return RenderBundle{}, &ApplyError{Class: ErrClassRender, Service: "postfix", Code: "template_postfix_main", Message: err.Error()}
	}
	files = append(files, FileSpec{Service: "postfix", Path: "/etc/postfix/main.cf.d/50-panel-mail.cf", Body: postfixMain})

	mailboxMap, err := execTemplate(postfixMailboxTpl, normalized)
	if err != nil {
		return RenderBundle{}, &ApplyError{Class: ErrClassRender, Service: "postfix", Code: "template_mailbox", Message: err.Error()}
	}
	files = append(files, FileSpec{Service: "postfix", Path: "/etc/postfix/virtual_mailbox_map", Body: mailboxMap})

	aliasMap, err := execTemplate(postfixAliasesTpl, normalized)
	if err != nil {
		return RenderBundle{}, &ApplyError{Class: ErrClassRender, Service: "postfix", Code: "template_aliases", Message: err.Error()}
	}
	files = append(files, FileSpec{Service: "postfix", Path: "/etc/postfix/virtual_aliases_map", Body: aliasMap})

	forwardMap, err := execTemplate(postfixForwardTpl, normalized)
	if err != nil {
		return RenderBundle{}, &ApplyError{Class: ErrClassRender, Service: "postfix", Code: "template_forwardings", Message: err.Error()}
	}
	files = append(files, FileSpec{Service: "postfix", Path: "/etc/postfix/virtual_forwardings_map", Body: forwardMap})

	dovecotSQL, err := execTemplate(dovecotSQLTpl, normalized)
	if err != nil {
		return RenderBundle{}, &ApplyError{Class: ErrClassRender, Service: "dovecot", Code: "template_dovecot_sql", Message: err.Error()}
	}
	files = append(files, FileSpec{Service: "dovecot", Path: "/etc/dovecot/dovecot-sql.conf.ext", Body: dovecotSQL})

	keyTable, err := execTemplate(opendkimKeyTableTpl, normalized)
	if err != nil {
		return RenderBundle{}, &ApplyError{Class: ErrClassRender, Service: "opendkim", Code: "template_keytable", Message: err.Error()}
	}
	files = append(files, FileSpec{Service: "opendkim", Path: "/etc/opendkim/KeyTable", Body: keyTable})

	signingTable, err := execTemplate(opendkimSigningTpl, normalized)
	if err != nil {
		return RenderBundle{}, &ApplyError{Class: ErrClassRender, Service: "opendkim", Code: "template_signingtable", Message: err.Error()}
	}
	files = append(files, FileSpec{Service: "opendkim", Path: "/etc/opendkim/SigningTable", Body: signingTable})

	trustedHosts, err := execTemplate(opendkimTrustedTpl, normalized)
	if err != nil {
		return RenderBundle{}, &ApplyError{Class: ErrClassRender, Service: "opendkim", Code: "template_trustedhosts", Message: err.Error()}
	}
	files = append(files, FileSpec{Service: "opendkim", Path: "/etc/opendkim/TrustedHosts", Body: trustedHosts})

	sort.Slice(files, func(i, j int) bool { return files[i].Path < files[j].Path })
	return RenderBundle{Revision: normalized.Revision, Files: files}, nil
}

func normalizeSnapshot(s Snapshot) Snapshot {
	out := s
	if out.Generated.IsZero() {
		out.Generated = time.Now().UTC()
	}
	if out.Revision == "" {
		out.Revision = out.Generated.UTC().Format(time.RFC3339)
	}
	sort.Slice(out.Domains, func(i, j int) bool {
		return strings.ToLower(out.Domains[i].Name) < strings.ToLower(out.Domains[j].Name)
	})
	sort.Slice(out.Users, func(i, j int) bool {
		return strings.ToLower(out.Users[i].Address) < strings.ToLower(out.Users[j].Address)
	})
	sort.Slice(out.Aliases, func(i, j int) bool {
		return strings.ToLower(out.Aliases[i].Address) < strings.ToLower(out.Aliases[j].Address)
	})
	sort.Slice(out.Forwardings, func(i, j int) bool {
		left := strings.ToLower(out.Forwardings[i].Source) + ":" + strings.ToLower(out.Forwardings[i].Destination)
		right := strings.ToLower(out.Forwardings[j].Source) + ":" + strings.ToLower(out.Forwardings[j].Destination)
		return left < right
	})
	sort.Slice(out.DKIMKeys, func(i, j int) bool {
		left := strings.ToLower(out.DKIMKeys[i].Domain) + ":" + strings.ToLower(out.DKIMKeys[i].Selector)
		right := strings.ToLower(out.DKIMKeys[j].Domain) + ":" + strings.ToLower(out.DKIMKeys[j].Selector)
		return left < right
	})
	sort.Strings(out.TrustedHosts)
	for i := range out.Aliases {
		sort.Strings(out.Aliases[i].Destinations)
	}
	return out
}

func execTemplate(raw string, payload Snapshot) ([]byte, error) {
	tpl, err := template.New("mail").Option("missingkey=error").Funcs(template.FuncMap{
		"lower": strings.ToLower,
		"join":  strings.Join,
	}).Parse(raw)
	if err != nil {
		return nil, err
	}
	var buf bytes.Buffer
	if err = tpl.Execute(&buf, payload); err != nil {
		return nil, err
	}
	if !bytes.HasSuffix(buf.Bytes(), []byte("\n")) {
		buf.WriteByte('\n')
	}
	return buf.Bytes(), nil
}

const postfixMainTpl = `# managed-by=panel-agent revision={{ .Revision }}
virtual_mailbox_domains = {{- range $i, $d := .Domains }}{{ if $i }},{{ end }}{{ lower $d.Name }}{{- end }}
virtual_mailbox_maps = hash:/etc/postfix/virtual_mailbox_map
virtual_alias_maps = hash:/etc/postfix/virtual_aliases_map,hash:/etc/postfix/virtual_forwardings_map
`

const postfixMailboxTpl = `# managed-by=panel-agent revision={{ .Revision }}
{{- range .Users }}
{{- if .Enabled }}
{{ lower .Address }} {{ .MailboxPath }}
{{- end }}
{{- end }}`

const postfixAliasesTpl = `# managed-by=panel-agent revision={{ .Revision }}
{{- range .Aliases }}
{{- if .Enabled }}
{{ lower .Address }} {{ join .Destinations "," }}
{{- end }}
{{- end }}`

const postfixForwardTpl = `# managed-by=panel-agent revision={{ .Revision }}
{{- range .Forwardings }}
{{- if .Enabled }}
{{ lower .Source }} {{ lower .Destination }}
{{- end }}
{{- end }}`

const dovecotSQLTpl = `# managed-by=panel-agent revision={{ .Revision }}
driver = pgsql
connect = host=127.0.0.1 dbname=panel user=panel password=__INJECTED_AT_RUNTIME__
default_pass_scheme = ARGON2ID
password_query = SELECT address as user, password_hash as password FROM mail_users WHERE address='%u' AND enabled=true;
user_query = SELECT '/var/vmail/' || address as home, 'maildir:/var/vmail/' || address as mail, 5000 as uid, 5000 as gid, quota_mb || 'M' as quota_rule FROM mail_users WHERE address='%u' AND enabled=true;
`

const opendkimKeyTableTpl = `# managed-by=panel-agent revision={{ .Revision }}
{{- range .DKIMKeys }}
{{- if .Enabled }}
{{ .Selector }}._domainkey.{{ lower .Domain }} {{ lower .Domain }}:{{ .Selector }}:{{ .PrivateKeyPath }}
{{- end }}
{{- end }}`

const opendkimSigningTpl = `# managed-by=panel-agent revision={{ .Revision }}
{{- range .DKIMKeys }}
{{- if .Enabled }}
*@{{ lower .Domain }} {{ .Selector }}._domainkey.{{ lower .Domain }}
{{- end }}
{{- end }}`

const opendkimTrustedTpl = `# managed-by=panel-agent revision={{ .Revision }}
127.0.0.1
localhost
{{- range .TrustedHosts }}
{{ . }}
{{- end }}`
