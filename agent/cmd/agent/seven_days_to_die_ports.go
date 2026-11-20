package main

import (
	"bytes"
	"encoding/xml"
	"fmt"
	"io"
	"os"
	"strconv"
)

const sevenDaysConfig = "serverconfig.xml"

func ensureSevenDaysToDiePorts(instanceDir string, payload map[string]any) error {
	if payloadValue(payload, "seven_days_to_die_managed_ports") != "true" || payloadValue(payload, "game_key", "game_type") != "seven_days_to_die" {
		return nil
	}
	reservations := make(map[string]string)
	applyPortReservations(reservations, payload)
	keys := []string{"PORT_GAME", "PORT_WEBDASHBOARD", "PORT_TELNET"}
	properties := []string{"ServerPort", "WebDashboardPort", "TelnetPort"}
	values := make(map[string]string, len(keys))
	used := make(map[int]bool, len(keys))
	for i, key := range keys {
		port, err := strconv.Atoi(reservations[key])
		if err != nil || port < 1 || port > 65535 || used[port] {
			return fmt.Errorf("7 Days to Die: missing, invalid or duplicate port reservation for %s", properties[i])
		}
		values[properties[i]] = strconv.Itoa(port)
		used[port] = true
	}

	root, err := os.OpenRoot(instanceDir)
	if err != nil {
		return err
	}
	defer func() { _ = root.Close() }()
	info, err := root.Lstat(sevenDaysConfig)
	if err != nil {
		return err
	}
	if !info.Mode().IsRegular() {
		return fmt.Errorf("%s must be a regular file", sevenDaysConfig)
	}
	file, err := root.Open(sevenDaysConfig)
	if err != nil {
		return err
	}
	defer func() { _ = file.Close() }()
	const maxConfigSize = 1 << 20
	original, err := io.ReadAll(io.LimitReader(file, maxConfigSize+1))
	if err != nil {
		return err
	}
	if len(original) > maxConfigSize {
		return fmt.Errorf("%s exceeds 1 MiB", sevenDaysConfig)
	}
	updated, err := setSevenDaysConfigPorts(original, properties, values)
	if err != nil || bytes.Equal(original, updated) {
		return err
	}
	return publishInstanceFile(instanceDir, sevenDaysConfig, bytes.NewReader(updated), info.Mode().Perm())
}

// Rewrite only the managed property elements so comments, additional settings
// and disabled-service flags from the game or the operator survive a start.
func setSevenDaysConfigPorts(original []byte, properties []string, values map[string]string) ([]byte, error) {
	decoder := xml.NewDecoder(bytes.NewReader(original))
	var output bytes.Buffer
	seen := make(map[string]bool, len(properties))
	depth, roots := 0, 0
	lastCopy := int64(0)
	for {
		offset := decoder.InputOffset()
		token, err := decoder.Token()
		if err == io.EOF {
			break
		}
		if err != nil {
			return nil, err
		}
		switch element := token.(type) {
		case xml.Directive:
			return nil, fmt.Errorf("unexpected XML directive in %s", sevenDaysConfig)
		case xml.StartElement:
			depth++
			if depth == 1 {
				roots++
				if roots != 1 || element.Name.Local != "ServerSettings" || element.Name.Space != "" {
					return nil, fmt.Errorf("%s must have a ServerSettings root", sevenDaysConfig)
				}
			}
			if depth != 2 || element.Name.Local != "property" || element.Name.Space != "" {
				continue
			}
			var name string
			valueIndex := -1
			for i, attr := range element.Attr {
				if attr.Name.Space != "" {
					continue
				}
				switch attr.Name.Local {
				case "name":
					name = attr.Value
				case "value":
					valueIndex = i
				}
			}
			value, managed := values[name]
			if !managed {
				continue
			}
			seen[name] = true
			if valueIndex >= 0 && element.Attr[valueIndex].Value == value {
				continue
			}
			if valueIndex >= 0 {
				element.Attr[valueIndex].Value = value
			} else {
				element.Attr = append(element.Attr, xml.Attr{Name: xml.Name{Local: "value"}, Value: value})
			}
			end := decoder.InputOffset()
			output.Write(original[lastCopy:offset])
			var tag bytes.Buffer
			encoder := xml.NewEncoder(&tag)
			if err := encoder.EncodeToken(element); err != nil {
				return nil, err
			}
			if err := encoder.Flush(); err != nil {
				return nil, err
			}
			if bytes.HasSuffix(original[offset:end], []byte("/>")) {
				output.Write(tag.Bytes()[:tag.Len()-1])
				output.WriteString("/>")
			} else {
				output.Write(tag.Bytes())
			}
			lastCopy = end
		case xml.EndElement:
			depth--
			if depth != 0 || len(seen) == len(properties) {
				continue
			}
			if decoder.InputOffset() == offset {
				return nil, fmt.Errorf("%s needs a ServerSettings element with a closing tag", sevenDaysConfig)
			}
			output.Write(original[lastCopy:offset])
			for _, property := range properties {
				if !seen[property] {
					fmt.Fprintf(&output, "\n  <property name=\"%s\" value=\"%s\" />", property, values[property])
				}
			}
			output.WriteByte('\n')
			lastCopy = offset
		}
	}
	if roots != 1 || depth != 0 {
		return nil, fmt.Errorf("missing ServerSettings root in %s", sevenDaysConfig)
	}
	output.Write(original[lastCopy:])
	return output.Bytes(), nil
}
