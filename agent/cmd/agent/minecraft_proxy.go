package main

import (
	"bytes"
	"fmt"
	"net"
	"os"
	"path/filepath"
	"strconv"
	"strings"

	"gopkg.in/yaml.v3"
)

func isMinecraftProxyTemplate(payload map[string]any) bool {
	for _, key := range []string{"minecraft_channel", "game_key", "game_type", "template_key"} {
		if strings.Contains(strings.ToLower(payloadValue(payload, key)), "bungeecord") {
			return true
		}
	}
	return false
}

func yamlMapValue(node *yaml.Node, key string) *yaml.Node {
	if node.Kind != yaml.MappingNode {
		return nil
	}
	for i := 0; i+1 < len(node.Content); i += 2 {
		if node.Content[i].Value == key {
			return node.Content[i+1]
		}
	}
	return nil
}
func setYamlMapValue(node *yaml.Node, key string, value *yaml.Node) {
	for i := 0; i+1 < len(node.Content); i += 2 {
		if node.Content[i].Value == key {
			node.Content[i+1] = value
			return
		}
	}
	node.Content = append(node.Content, &yaml.Node{Kind: yaml.ScalarNode, Tag: "!!str", Value: key}, value)
}
func yamlScalar(value string) *yaml.Node {
	return &yaml.Node{Kind: yaml.ScalarNode, Tag: "!!str", Value: value}
}

func ensureBungeeConfiguration(dir string, payload map[string]any) error {
	path := filepath.Join(dir, "config.yml")
	raw, err := readMinecraftConfig(dir, "config.yml")
	if err != nil && !os.IsNotExist(err) {
		return err
	}
	var document yaml.Node
	if len(bytes.TrimSpace(raw)) > 0 {
		if err := yaml.Unmarshal(raw, &document); err != nil {
			return fmt.Errorf("invalid BungeeCord config.yml: %w", err)
		}
	}
	if len(document.Content) == 0 {
		document.Kind = yaml.DocumentNode
		document.Content = []*yaml.Node{{Kind: yaml.MappingNode, Tag: "!!map"}}
	}
	root := document.Content[0]
	if root.Kind != yaml.MappingNode {
		return fmt.Errorf("BungeeCord config.yml must be a mapping")
	}
	tv := buildInstanceTemplateValues(dir, dir, payloadValue(payload, "required_ports"), parsePayloadPorts(payload), payload)
	if err := validateMinecraftPort(tv["PORT_GAME"]); err != nil {
		return err
	}
	listeners := yamlMapValue(root, "listeners")
	if listeners == nil {
		listeners = &yaml.Node{Kind: yaml.SequenceNode, Tag: "!!seq", Content: []*yaml.Node{{Kind: yaml.MappingNode, Tag: "!!map"}}}
		setYamlMapValue(root, "listeners", listeners)
	}
	if listeners.Kind != yaml.SequenceNode || len(listeners.Content) != 1 || listeners.Content[0].Kind != yaml.MappingNode {
		return fmt.Errorf("BungeeCord requires exactly one managed listener; additional listeners need allocated ports")
	}
	listener := listeners.Content[0]
	bind := payloadValue(payload, "bind_ip")
	if bind == "" {
		bind = "0.0.0.0"
	}
	if net.ParseIP(bind) == nil {
		return fmt.Errorf("invalid proxy bind IP")
	}
	setYamlMapValue(listener, "host", yamlScalar(net.JoinHostPort(bind, tv["PORT_GAME"])))
	enabled := tv["PORT_QUERY"] != ""
	setYamlMapValue(listener, "query_enabled", &yaml.Node{Kind: yaml.ScalarNode, Tag: "!!bool", Value: strconv.FormatBool(enabled)})
	if enabled {
		if err := validateMinecraftPort(tv["PORT_QUERY"]); err != nil {
			return err
		}
		setYamlMapValue(listener, "query_port", &yaml.Node{Kind: yaml.ScalarNode, Tag: "!!int", Value: tv["PORT_QUERY"]})
	}
	if yamlMapValue(listener, "motd") == nil {
		setYamlMapValue(listener, "motd", yamlScalar(tv["SERVER_NAME"]))
	}
	if yamlMapValue(listener, "max_players") == nil {
		setYamlMapValue(listener, "max_players", &yaml.Node{Kind: yaml.ScalarNode, Tag: "!!int", Value: tv["MAX_PLAYERS"]})
	}
	if yamlMapValue(root, "online_mode") == nil {
		setYamlMapValue(root, "online_mode", &yaml.Node{Kind: yaml.ScalarNode, Tag: "!!bool", Value: "true"})
	}
	if yamlMapValue(root, "servers") == nil {
		backend := tv["BUNGEE_BACKEND"]
		host, port, err := net.SplitHostPort(backend)
		if err != nil || host == "" {
			return fmt.Errorf("configure a BungeeCord backend as host:port before installation")
		}
		if err := validateMinecraftPort(port); err != nil {
			return err
		}
		if port == tv["PORT_GAME"] && (host == "127.0.0.1" || host == "localhost" || host == bind) {
			return fmt.Errorf("BungeeCord backend must not point to its own listener")
		}
		servers := &yaml.Node{Kind: yaml.MappingNode, Tag: "!!map"}
		lobby := &yaml.Node{Kind: yaml.MappingNode, Tag: "!!map"}
		setYamlMapValue(lobby, "address", yamlScalar(backend))
		setYamlMapValue(lobby, "restricted", &yaml.Node{Kind: yaml.ScalarNode, Tag: "!!bool", Value: "false"})
		setYamlMapValue(servers, "lobby", lobby)
		setYamlMapValue(root, "servers", servers)
		setYamlMapValue(listener, "priorities", &yaml.Node{Kind: yaml.SequenceNode, Tag: "!!seq", Content: []*yaml.Node{yamlScalar("lobby")}})
	}
	var buffer bytes.Buffer
	encoder := yaml.NewEncoder(&buffer)
	encoder.SetIndent(2)
	if err := encoder.Encode(&document); err != nil {
		return err
	}
	if err := encoder.Close(); err != nil {
		return err
	}
	return writeMinecraftConfig(dir, path, buffer.Bytes())
}
