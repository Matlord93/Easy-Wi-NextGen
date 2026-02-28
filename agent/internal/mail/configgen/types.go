package configgen

type DomainConfig struct {
	Domain    string
	Mailboxes []string
	Aliases   map[string]string
}

type Snapshot struct {
	NodeID  string
	Domains []DomainConfig
}

type RenderedFile struct {
	Path    string
	Content []byte
}
