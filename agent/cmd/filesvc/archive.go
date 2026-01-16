package main

import (
	"archive/tar"
	"archive/zip"
	"compress/gzip"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strings"
)

func extractArchive(archivePath, destination string) error {
	switch {
	case strings.HasSuffix(strings.ToLower(archivePath), ".zip"):
		return extractZip(archivePath, destination)
	case strings.HasSuffix(strings.ToLower(archivePath), ".tar"):
		return extractTar(archivePath, destination, false)
	case strings.HasSuffix(strings.ToLower(archivePath), ".tar.gz"):
		return extractTar(archivePath, destination, true)
	case strings.HasSuffix(strings.ToLower(archivePath), ".tgz"):
		return extractTar(archivePath, destination, true)
	default:
		return errors.New("unsupported archive format")
	}
}

func extractZip(path, destination string) error {
	reader, err := zip.OpenReader(path)
	if err != nil {
		return fmt.Errorf("open zip: %w", err)
	}
	defer reader.Close()

	for _, file := range reader.File {
		target, err := sanitizeInstancePath(destination, file.Name)
		if err != nil {
			return err
		}
		if file.FileInfo().IsDir() {
			if err := os.MkdirAll(target, 0750); err != nil {
				return fmt.Errorf("mkdir %s: %w", target, err)
			}
			continue
		}

		if err := os.MkdirAll(filepath.Dir(target), 0750); err != nil {
			return fmt.Errorf("mkdir %s: %w", target, err)
		}

		src, err := file.Open()
		if err != nil {
			return fmt.Errorf("open zip entry: %w", err)
		}
		if err := writeFileAtomic(target, src, 0o640); err != nil {
			src.Close()
			return err
		}
		src.Close()
	}

	return nil
}

func extractTar(path, destination string, gzipCompressed bool) error {
	file, err := os.Open(path)
	if err != nil {
		return fmt.Errorf("open tar: %w", err)
	}
	defer file.Close()

	var reader io.Reader = file
	if gzipCompressed {
		gz, err := gzip.NewReader(file)
		if err != nil {
			return fmt.Errorf("open gzip: %w", err)
		}
		defer gz.Close()
		reader = gz
	}

	tarReader := tar.NewReader(reader)
	for {
		header, err := tarReader.Next()
		if err == io.EOF {
			break
		}
		if err != nil {
			return fmt.Errorf("read tar: %w", err)
		}
		if header == nil || header.Name == "" {
			continue
		}

		target, err := sanitizeInstancePath(destination, header.Name)
		if err != nil {
			return err
		}

		switch header.Typeflag {
		case tar.TypeDir:
			if err := os.MkdirAll(target, 0750); err != nil {
				return fmt.Errorf("mkdir %s: %w", target, err)
			}
		case tar.TypeReg:
			if err := os.MkdirAll(filepath.Dir(target), 0750); err != nil {
				return fmt.Errorf("mkdir %s: %w", target, err)
			}
			if err := writeFileAtomic(target, tarReader, 0o640); err != nil {
				return err
			}
		}
	}

	return nil
}
